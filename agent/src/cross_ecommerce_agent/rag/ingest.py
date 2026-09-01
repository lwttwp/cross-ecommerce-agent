# 知识库服务类
import os
import cross_ecommerce_agent.config as config
from cross_ecommerce_agent.rag.retriever import HybridRetriever
import hashlib
from cross_ecommerce_agent.rag.embeddings import OpenAICompatibleEmbeddings
from langchain_chroma import Chroma
from langchain_text_splitters import MarkdownHeaderTextSplitter,RecursiveCharacterTextSplitter
from datetime import datetime
import logging
logger = logging.getLogger(__name__)

def check_md5(md5_str: str):
    if not os.path.exists(config.md5_path):
        open(config.md5_path, 'w').close()
        return False
    with open(config.md5_path, 'r', encoding='utf-8') as f:
        for line in f:
            if line.strip() == md5_str:
                return True
        return False

def save_md5(md5_str: str):
    with open(config.md5_path, 'a', encoding='utf-8') as f:
        f.write(md5_str + '\n')
        return True

def get_string_md5(input_str: str, encoding='utf-8'):
    # 将字符串转换为bytes字节数组。二进制
    str_bytes = input_str.encode(encoding=encoding)

    # 创建MD5对象
    md5_obj = hashlib.md5()
    # 将bytes字节数组传入MD5对象进行哈希计算
    md5_obj.update(str_bytes)
    # 获取哈希计算后的MD5值
    md5_str = md5_obj.hexdigest()
    return md5_str

class KnowledgeBaseService(object):
    def __init__(self):
        # 创建向量数据库存储路径，不存在则创建
        os.makedirs(config.CHROMA_PERSIST_DIR, exist_ok=True)
        self.chroma = Chroma(
            collection_name=config.CHROMA_COLLECTION, # 集合名称,类似数据库中的表
            embedding_function=OpenAICompatibleEmbeddings(
                model=config.EMBEDDING_MODEL,
                api_key=config.EMBEDDING_API_KEY,
                base_url=config.EMBEDDING_BASE_URL
            ),
            persist_directory=config.CHROMA_PERSIST_DIR, # 数据库路径,类似数据库中的文件夹
        )
        # self.spliter = RecursiveCharacterTextSplitter(
        #     chunk_size=config.chunk_size,# 每块的大小，单位字节
        #     chunk_overlap=config.chunk_overlap,# 每块之间的重叠大小，单位字节
        #     separators = config.separators,# 分隔符，用于将字符串分割成块
        #     length_function = len # 计算字符串长度的函数
        # )
        self.spliter = MarkdownHeaderTextSplitter(headers_to_split_on=[("##", "section")])

    # 将传入的字符串进行向量化，存入向量数据库
    def upload_by_str(self, data:str, file_name):
        knowledge_chunks = self.spliter.split_text(data)
        new_chunk = []
        new_ids = []
        for chunk in knowledge_chunks:
            md5_hex = get_string_md5(chunk.page_content)
            if check_md5(md5_hex):
                logger.info("当前块已存在")
                continue
            chunk.metadata['source'] = file_name
            chunk.metadata['create_time'] = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            chunk.metadata['operator'] = '李万涛'
            new_chunk.append(chunk)
            new_ids.append(md5_hex)
        if new_chunk:
            self.chroma.add_documents(new_chunk, ids=new_ids)
            for new_id in new_ids:
                save_md5(new_id)
        return "数据已上传"

if __name__ == '__main__':
    service = KnowledgeBaseService()
    hybrid = HybridRetriever()
    for md_file in os.listdir(config.RAG_DOCS_DIR):
        with open(config.RAG_DOCS_DIR / md_file, 'r', encoding='utf-8') as f:
            res = service.upload_by_str(f.read(), md_file)
            logger.info(f"文件{md_file}上传结果:{res}")
    #入库后要调 HybridRetriever.rebuild_index() 刷新 BM25 索引(
    hybrid.rebuild_index()
