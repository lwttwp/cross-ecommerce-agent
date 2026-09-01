import cross_ecommerce_agent.config as config
from langchain_deepseek import ChatDeepSeek
from dotenv import load_dotenv

_llm = None

def get_llm(temperature = 0.3):
    global _llm
    if _llm is None:
        _llm = ChatDeepSeek(
            model=config.DEEPSEEK_MODEL,
            extra_body={
                "thinking": {
                    "type": "disabled"
                }
            },
            temperature = temperature
        )
    return _llm


if __name__ == '__main__':
    print(get_llm())