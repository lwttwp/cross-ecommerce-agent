from pathlib import Path
from dotenv import load_dotenv
import os
import logging
from pathlib import Path


LOG_DIR = Path(__file__).resolve().parent.parent.parent / "logs"
LOG_DIR.mkdir(exist_ok=True)

logging.basicConfig(
    level=logging.INFO,                                  # DEBUG/INFO/WARNING/ERROR
    format="%(asctime)s %(levelname)s [%(name)s] %(message)s",
    handlers=[
        logging.StreamHandler(),                         # 控制台
        logging.FileHandler(LOG_DIR / "agent.log", encoding="utf-8"),  # 文件
    ],
)

BASE_DIR = Path(__file__).resolve().parent.parent.parent   # agent/
load_dotenv(BASE_DIR / '.env')

DEEPSEEK_API_KEY = os.getenv('DEEPSEEK_API_KEY', '')
DEEPSEEK_BASE_URL = os.getenv('DEEPSEEK_BASE_URL', '')
DEEPSEEK_MODEL = os.getenv('DEEPSEEK_MODEL', '')
BIZ_API_BASE = os.getenv('BIZ_API_BASE', '')
BIZ_API_TOKEN_AGENT = os.getenv('BIZ_API_TOKEN_AGENT', '')
BIZ_API_TOKEN_ADMIN = os.getenv('BIZ_API_TOKEN_ADMIN', '')
CHROMA_PERSIST_DIR = (BASE_DIR / os.getenv('CHROMA_PERSIST_DIR', 'data/chroma')).resolve()
CHROMA_COLLECTION  = os.getenv('CHROMA_COLLECTION', 'aftersale_policy')
RAG_DOCS_DIR = (BASE_DIR / os.getenv('RAG_DOCS_DIR', 'src/cross_ecommerce_agent/rag/documents')).resolve()
MCP_SERVER_URL = os.getenv('MCP_SERVER_URL', '')
AGENT_PORT = os.getenv('AGENT_PORT', '')
EMBEDDING_BASE_URL = os.getenv('EMBEDDING_BASE_URL', '')
EMBEDDING_API_KEY = os.getenv('EMBEDDING_API_KEY', '')
EMBEDDING_MODEL = os.getenv('EMBEDDING_MODEL', '')
RETRIEVE_K = 3 # 向量检索(get_retriever)返回条数
VECTOR_TOP_K = 3 # 混合检索时,向量路召回条数
BM25_TOP_K = 6 # BM25 按词匹配,命中不准但全,要多捞一些给融合留余地(6 = 2×3,常用比例)
"""
    RRF_K 越大,两条路的排名差距被压得越平(都趋向 1/60、1/61),
    融合结果越"民主"——谁排第 1 谁排第 5 差别不大,双路命中的优势更明显。
    RRF_K 越小,排名越"卷"——排第 1 的拿 1/1,排第 5 的拿 1/5,
    第一名权重被放大,单路高排名就能压过双路命中。
    60 是标准值,绝大部分场景不用动。
    想让精确命中更突出就调小(如 30),想让语义更平滑就调大(如 100)。
"""
RRF_K = 60 # RRF 融合常数,公式 score = Σ 1/(RRF_K + rank)
md5_path = BASE_DIR / 'md5.txt'

RAG_ANSWER_PROMPT = """你是跨境电商平台的售后客服助手。请严格依据下面的【参考资料】回答用户问题,不要使用参考资料以外的知识。
回答要求:
1. 只依据参考资料回答,严禁编造参考资料中没有的内容
2. 引用溯源:每个关键结论后标注来源,格式 [来源:xxx.md]
3. 参考资料查不到时,明确回复"知识库暂未收录该信息,建议转人工客服处理",不要猜测
4. 若参考资料之间说法不一致,以更具体的条款为准,并同时注明两个来源
5. 用中文回答,专业、简洁、友好,要点式呈现,不要长篇大论
6. 涉及金额、天数、条款编号等关键信息,必须与参考资料完全一致,不得改写
7. 涉及明确条款（天数/金额/责任方等）时，必须直接引用原文给出确定结论，
   再说明限制条件；严禁因上下文存在例外条款（如不可退货商品）而否定主条款
8. 条款归类作答：用户问哪一类退货/政策，就以该类的【主条款】回答（如问"无理由
   退货"→ 用"无理由退货"条款的天数/运费；问"质量问题退货"→ 用质量问题条款）。
   其他类型的条款（质量问题/定制类/运费承担等）只能作为补充说明，
   严禁用另一类条款的数字或规则替代主条款作答
"""
AGENT_SYSTEM_PROMPT = """你是跨境电商平台的智能订单助手,为客服运营人员服务。你可以调用工具查询业务系统。

工作准则:
1. 查询类需求(订单/物流/商品/客户/退款/任务)必须调用工具获取真实数据,禁止凭记忆或猜测回答
2. 写操作(创建订单、取消订单、修改地址、申请退款、创建任务)执行前,必须先向用户确认:
   - 核对订单号、金额等关键参数
   - 申请退款时说明"提交后将进入管理员人工审批"
3. 用户未提供订单号时,先向用户索要,不要假设或编造
4. 工具返回 error 时,如实告知用户失败原因,不得伪造成功结果
5. 报表/导出任务创建成功后,告知任务编号,并说明稍后可查询结果
6. 回答使用中文,简洁专业;订单号、金额、状态等关键信息必须与工具返回完全一致
7. 每轮只做一件事:要么调用一个工具,要么直接回答用户
"""

EXTRACT_REFUND_PROMPT = """从客服消息中提取退款申请信息,输出 JSON:
{{
  "order_no": "订单号,格式 CE+12位数字,如 CE202608241024",
  "reason": "退款原因",
  "amount": "退款金额(纯数字,不含货币符号),如 500 美元 → 500"
}}

要求:
1. 只提取文本中明确出现的信息,绝不编造
2. 文本中没有的信息,一律填 null
3. 金额未提及则 null(表示全额退款)

客服消息:
{text}
"""
CHECKPOINT_DB_DSN = os.getenv('CHECKPOINT_DB_DSN', '')

REFUND_CONFIRM_THRESHOLD = 500   # 全额退款超过此金额(USD)需确认
