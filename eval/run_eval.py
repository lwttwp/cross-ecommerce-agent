# -*- coding: utf-8 -*-
"""
评测脚本:读取 cases.jsonl,逐条跑 LangGraph,输出通过率报告。

用法:
    python run_eval.py                     # 跑全部
    python run_eval.py --category 退款售后  # 只跑某一类
    python run_eval.py --max 5             # 只跑前 5 条(快速验证)
    python run_eval.py --json report.json  # 结果同时写文件

设计要点:
- 每条用例独立 thread_id,避免 checkpoint 状态串扰
- 正向退款用例(refund-*)期望走到 refund_confirm 中断即通过,不 resume、不提交,
  保证评测无写操作副作用
- 负向用例(不存在订单/非法状态退款/越权操作)期望被业务层拦截,断言拒绝话术
- 单条超时(默认 120s)防止 LLM 异常卡死拖垮整个评测
"""
import argparse
import io
import json
import os
import sys
import time
import uuid
from concurrent.futures import ThreadPoolExecutor, TimeoutError as FutTimeout

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")

# ---------- 环境准备 ----------
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))  # agent/
sys.path.insert(0, os.path.join(BASE_DIR, "src"))

try:
    from dotenv import load_dotenv
    load_dotenv(os.path.join(BASE_DIR, ".env"))
except ImportError:
    pass  # 环境变量已注入时无需 dotenv

from cross_ecommerce_agent.graph.build import graph  # noqa: E402

EVAL_DIR = os.path.dirname(os.path.abspath(__file__))
CASES_PATH = os.path.join(EVAL_DIR, "cases.jsonl")


# ---------- 执行与判定 ----------
def run_case(case: dict, timeout: int = 120) -> dict:
    """执行一条用例,返回结构化结果,不做断言。"""
    config = {"configurable": {"thread_id": "eval-" + uuid.uuid4().hex[:12]}}
    started = time.time()

    def _invoke():
        return graph.invoke({"user_input": case["input"]}, config=config)

    try:
        with ThreadPoolExecutor(max_workers=1) as ex:
            future = ex.submit(_invoke)
            res = future.result(timeout=timeout)
    except FutTimeout:
        return {"status": "timeout", "elapsed": time.time() - started}
    except Exception as e:  # noqa: BLE001
        return {"status": "error", "detail": f"{type(e).__name__}: {e}",
                "elapsed": time.time() - started}

    elapsed = time.time() - started
    if "__interrupt__" in res:
        types = [i.value.get("type") for i in res["__interrupt__"]]
        return {"status": "interrupt", "types": types,
                "value": res["__interrupt__"][0].value, "elapsed": elapsed}
    return {"status": "done", "answer": res.get("answer", ""), "elapsed": elapsed}


def judge(case: dict, result: dict) -> tuple[bool, str]:
    """返回 (是否通过, 说明)。"""
    exp = case["expect"]
    t = exp["type"]

    if t == "interrupt":
        want = exp.get("keyword", "refund_confirm")
        if result["status"] == "interrupt":
            ok = want in result.get("types", [])
            return ok, f"中断类型={result.get('types')}"
        return False, f"期望中断[{want}],实际 {result['status']}"

    if result["status"] == "interrupt":
        return False, f"意外中断: {result.get('types')}"
    if result["status"] == "timeout":
        return False, f"超时({result.get('elapsed', 0):.1f}s)"
    if result["status"] == "error":
        return False, f"异常: {result.get('detail')}"

    answer = result["answer"]
    if t == "contains":
        miss = [k for k in exp["keywords"] if k not in answer]
        if miss:
            return False, f"缺少关键词 {miss} | 回答: {answer[:120]}"
        return True, "关键词全部命中"
    if t == "contains_any":
        hit = [k for k in exp["keywords"] if k in answer]
        if not hit:
            return False, f"未命中任一 {exp['keywords']} | 回答: {answer[:120]}"
        return True, f"命中 {hit}"
    if t == "not_contains":
        bad = [k for k in exp["keywords"] if k in answer]
        if bad:
            return False, f"出现禁止词 {bad} | 回答: {answer[:120]}"
        return True, "未出现禁止词"
    if t == "ok":
        return True, "无异常即通过"
    return False, f"未知断言类型: {t}"


# ---------- 入口 ----------
def main():
    ap = argparse.ArgumentParser(description="Agent 评测脚本")
    ap.add_argument("--category", help="只跑指定类别")
    ap.add_argument("--max", type=int, help="最多跑 N 条")
    ap.add_argument("--json", help="结果报告输出路径")
    ap.add_argument("--timeout", type=int, default=120, help="单条超时秒数")
    args = ap.parse_args()

    with open(CASES_PATH, encoding="utf-8") as f:
        cases = [json.loads(line) for line in f if line.strip()]
    print(f"加载用例 {len(cases)} 条")

    if args.category:
        cases = [c for c in cases if c["category"] == args.category]
        print(f"过滤类别 [{args.category}] → {len(cases)} 条")
    if args.max:
        cases = cases[: args.max]
        print(f"限制前 {args.max} 条")

    results = []
    for i, case in enumerate(cases, 1):
        tag = f"[{i}/{len(cases)}] {case['id']} ({case['category']})"
        print(f"{tag} 输入: {case['input']}", flush=True)
        result = run_case(case, timeout=args.timeout)
        passed, note = judge(case, result)
        results.append({"case": case, "result": result, "passed": passed, "note": note})
        mark = "✅" if passed else "❌"
        print(f"   {mark} {note}")
        print()

    # ---------- 汇总 ----------
    total = len(results)
    passed_n = sum(1 for r in results if r["passed"])
    print("=" * 60)
    print(f"总体通过率: {passed_n}/{total} = {passed_n / total * 100:.1f}%")

    by_cat = {}
    for r in results:
        c = r["case"]["category"]
        by_cat.setdefault(c, [0, 0])
        by_cat[c][1] += 1
        if r["passed"]:
            by_cat[c][0] += 1
    print("-" * 60)
    for cat, (p, n) in by_cat.items():
        print(f"  {cat}: {p}/{n} ({p / n * 100:.0f}%)")

    fails = [r for r in results if not r["passed"]]
    if fails:
        print("-" * 60)
        print("失败明细:")
        for r in fails:
            c = r["case"]
            print(f"  ❌ {c['id']} [{c['category']}]")
            print(f"     输入: {c['input']}")
            print(f"     预期: {json.dumps(c['expect'], ensure_ascii=False)}")
            print(f"     原因: {r['note']}")

    if args.json:
        report = {
            "total": total, "passed": passed_n,
            "rate": round(passed_n / total * 100, 1) if total else 0,
            "by_category": {k: {"passed": v[0], "total": v[1]} for k, v in by_cat.items()},
            "results": [
                {"id": r["case"]["id"], "category": r["case"]["category"],
                 "input": r["case"]["input"], "passed": r["passed"], "note": r["note"]}
                for r in results
            ],
        }
        with open(args.json, "w", encoding="utf-8") as f:
            json.dump(report, f, ensure_ascii=False, indent=2)
        print(f"\n报告已写入: {args.json}")

    return 0 if passed_n == total else 1


if __name__ == "__main__":
    sys.exit(main())
