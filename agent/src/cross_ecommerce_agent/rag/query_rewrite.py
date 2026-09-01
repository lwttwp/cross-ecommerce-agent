"""查询改写(Query Rewrite):检索前用 LLM 把口语化提问改写成检索友好的查询。

为什么需要:
- 用户提问是口语("那个鞋子磨脚咋办"),知识库文档是书面语("退换货政策")
- 直接检索口语原文,向量和 BM25 都容易偏
- LLM 改写后提取核心实体(商品/金额/快递商/条款号),检索命中率更高

两种模式:
- rewrite():     单查询改写,1 问 → 1 条精炼查询
- multi_rewrite(): Multi-Query,1 问 → n 条不同角度查询,各自检索后合并(提高召回)
"""
from langchain_openai import ChatOpenAI
import cross_ecommerce_agent.config as config
import re

# 单查询改写:产出精炼、保留实体的检索式查询
REWRITE_PROMPT = """你是跨境电商售后知识库的查询改写专家。把用户的提问改写成适合知识库检索的查询。

要求:
1. 保留核心实体:商品名、金额、快递商、条款编号、政策名称等
2. 去掉口语化表达、语气词、冗余修饰、指代("那个""这个""它")
3. 输出简洁的检索式查询,10-25 个字
4. 只输出改写后的查询本身,不要解释、不要加引号

用户提问:{query}
改写后的查询:"""

# Multi-Query:从不同角度改写,扩大召回面
MULTI_REWRITE_PROMPT = """你是跨境电商售后知识库的查询改写专家。把用户的提问改写成 {n} 个不同角度的检索查询,分别侧重:

1. 官方术语角度:使用知识库里的政策名/正式名称(如"退货政策""运费补贴")
2. 用户口语角度:保留用户的自然说法(如"退掉""几天到")
3. 关键细节角度:突出具体数字、快递商、条款号等硬信息(如"30 天""DHL""条款 4.2")

要求:
- 每个查询 10-25 个字,保留核心实体
- 每行一个查询,前面加序号(1. 2. 3.),不要其他内容
- 严禁编造知识库中不存在的政策名称;不确定正式名称时,直接使用原文中的说法

用户提问:{query}
{n}个查询:"""


class QueryRewriter:
    """基于 DeepSeek 的查询改写器。"""

    def __init__(self, temperature: float = 0.0):
        self._llm = ChatOpenAI(
            model=config.DEEPSEEK_MODEL,
            api_key=config.DEEPSEEK_API_KEY,
            base_url=config.DEEPSEEK_BASE_URL,
            temperature=temperature,  # 改写要稳定,0 输出确定性最高
        )

    def rewrite(self, query: str) -> str:
        """单查询改写:返回一条精炼检索查询。"""
        resp = self._llm.invoke(REWRITE_PROMPT.format(query=query))
        return resp.content.strip().strip('"').strip("'")

    def multi_rewrite(self, query: str, n: int = 3) -> list[str]:
        """Multi-Query:返回 n 条不同角度的检索查询。"""
        resp = self._llm.invoke(MULTI_REWRITE_PROMPT.format(query=query, n=n))
        lines = [ln.strip() for ln in resp.content.strip().splitlines() if ln.strip()]
        queries = []
        for ln in lines:
            # 去掉 "1." 之类序号
            if ln[:2].replace('.', '').isdigit():
                ln = ln.split('.', 1)[1].strip()
            if ln:
                queries.append(ln.strip('"').strip("'"))
        return [query] + queries[:n]


MULTI_HINT = re.compile(r'和|以及|还有|顺便|同时|对比|区别|分别|另外|以及|还有没有')
MULTI_QUERY_MAX_LEN = 20

def need_multi(query: str) -> bool:
    """判断是否值得多查询改写。"""
    if len(query) > MULTI_QUERY_MAX_LEN:
        return True                    # 长问题大概率多主题
    if MULTI_HINT.search(query):
        return True                    # 连接词 = 多主题信号
    return False

if __name__ == '__main__':
    rw = QueryRewriter()
    test_queries = [
        "那个鞋子磨脚不舒服想退掉",
        "DHL 快递要几天能到",
        "礼品卡买了不想要能退钱吗",
    ]
    for q in test_queries:
        print(f"\n原始: {q}")
        print(f"改写: {rw.rewrite(q)}")
        print("多角度:")
        for i, mq in enumerate(rw.multi_rewrite(q), 1):
            print(f"  {i}. {mq}")
