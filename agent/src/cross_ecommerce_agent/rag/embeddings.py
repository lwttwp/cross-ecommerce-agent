# 放 langchain-openai 1.6.0 默认 tiktoken_enabled=True，
# 把输入文本分词成 token id 数组再发给 API。
# OpenAI 官方接口支持传 token id（所以 langchain 这么设计），
# 但百炼兼容端点只认字符串 → 400。
from openai import OpenAI

class OpenAICompatibleEmbeddings:
    """OpenAI 兼容 embedding 适配器(百炼/硅基流动等)。
    不用 langchain-openai:它的 tiktoken 分词会把文本转成 token id,
    兼容端点(DashScope)只认字符串,会 400。
    百炼单次 batch 上限 10 条,超出会 400,因此分批提交。"""

    BATCH_SIZE = 10  # 百炼 embedding 单次请求上限

    def __init__(self, model: str, api_key: str, base_url: str):
        self._client = OpenAI(api_key=api_key, base_url=base_url)
        self._model = model

    def embed_documents(self, texts: list[str]) -> list[list[float]]:
        embeddings: list[list[float]] = []
        for i in range(0, len(texts), self.BATCH_SIZE):
            batch = texts[i:i + self.BATCH_SIZE]
            resp = self._client.embeddings.create(model=self._model, input=batch)
            embeddings.extend(d.embedding for d in resp.data)
        return embeddings

    def embed_query(self, text: str) -> list[float]:
        return self.embed_documents([text])[0]