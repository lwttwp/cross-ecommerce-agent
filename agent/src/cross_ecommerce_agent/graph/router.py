import re
from pydantic import BaseModel, Field
from cross_ecommerce_agent.llm.client import get_llm

# ---------- ① 规则预检 ----------
ORDER_NO_RE = re.compile(r'CE\d{12}')                # 订单号: CE + 12位数字
TRACKING_RE = re.compile(r'CE-TRK-\w+', re.I)        # 物流单号
SKU_RE      = re.compile(r'SKU-\d{3,}', re.I)        # 商品编码
REPORT_RE   = re.compile(r'报表|统计|导出|汇总|销售|退款率')     # 报表导出\
REFUND_RE = re.compile(r'申请退款|申请退货|帮我退|办理退款|我要退款|想退|要求退款', re.I)    # 退款申请
QUERY_VERB_RE = re.compile(
    r'查|查询|看看|看下|查看|物流|跟踪|追踪|到哪|在哪|什么状态|进度|详情',
)
APPROVE_RE = re.compile(r'审批|审核', re.I)   # 管理员动作,Agent 无此工具
def rule_precheck(text: str) -> str | None:
    """硬信号预检:命中直接锁定意图;返回 None 表示交给 LLM"""
    if REPORT_RE.search(text):
        return 'report'
    if REFUND_RE.search(text):
        return 'refund'
    if (ORDER_NO_RE.search(text) and QUERY_VERB_RE.search(text)) or TRACKING_RE.search(text) or SKU_RE.search(text) or APPROVE_RE.search(text):
        return 'query'

    return None

class Intent(BaseModel):
    intent: str = Field(description="policy | query | refund | report")
    order_no: str | None = None     # 顺手抽出订单号等关键参数
    reason: str = ""                # 分类理由,便于调试

INTENT_PROMPT = """你是跨境电商订单助手的意图分类器。把用户消息分为 4 类之一:
- policy: 问售后政策/规则(退货、关税、礼品卡、积分、运费),用知识库回答即可
- query:  查订单、物流、商品、客户信息(只读查询)
- refund: 申请退款/退货(写操作,需审批)
- report: 生成报表/统计数据

特别注意区分:
- 问规则是 policy("退货要多久"),申请操作是 refund("帮我申请退货")。
- 取消订单、修改地址属于订单操作(query 类,走工具),不是退款申请
- 审批/审核退款单是管理员权限;客服请求审批时引导联系管理员,不进入 refund 流程
用户消息: {text}"""

class IntentRouter:
    def __init__(self):
        self._llm = get_llm().with_structured_output(Intent)
    def classify(self, text: str) -> Intent:
        pres = rule_precheck(text)
        if pres:
            m = ORDER_NO_RE.search(text)  # 规则命中时,正则直接抽订单号
            return Intent(intent=pres, reason="规则预检命中",order_no = m.group(0) if m else None)
        else:
            try:
                return  self._llm.invoke(INTENT_PROMPT.format(text=text))
            except Exception as e:
                return Intent(intent="policy")


def route_by_intent(state) -> str:
    return state["intent"]

if __name__ == "__main__":
    intent = IntentRouter()
    res = intent.classify("订单CE202608240006申请退款，不想要了")
    print(res)
