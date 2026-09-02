import cross_ecommerce_agent.config as config
import httpx
from langchain_core.tools import tool


class BusinessError(Exception):
    """业务系统错误:HTTP 非 2xx 或响应 code != 0 时抛出。"""

    def __init__(self, message: str, code: int | None = None):
        super().__init__(message)
        self.code = code      # 业务错误码,便于上层区分错误类型


class BusinessApiClient:
    """业务系统 REST 客户端:统一鉴权、超时、响应解析。"""

    def __init__(self):
        self.client = httpx.Client(
            base_url=config.BIZ_API_BASE,
            headers={
                "Content-Type": "application/json",
                "Authorization": f"Bearer {config.BIZ_API_TOKEN_AGENT}"
            },
            timeout=10.0
        )

    def get(self, path: str, params: dict | None = None) -> dict:
        resp = self.client.get(path, params=params)
        return self._parse(resp)

    def post(self, path: str, json: dict | None = None) -> dict:
        resp = self.client.post(path, json=json)
        return self._parse(resp)

    def get_text(self, path: str, params: dict | None = None) -> str:
        """返回原始文本(用于 CSV 下载),不走 JSON 解析。"""
        resp = self.client.get(path, params=params)
        resp.raise_for_status()
        return resp.text

    def _parse(self, resp: httpx.Response) -> dict:
        # 状态码前置:nginx 502/500 可能返回 HTML,resp.json() 会抛 ValueError
        try:
            body = resp.json()
        except ValueError:
            raise BusinessError(f"HTTP {resp.status_code}: 非 JSON 响应")
        if body.get("code") != 0:
            # 优先透出业务消息(如"订单不存在"),HTTP 状态码兜底
            raise BusinessError(
                body.get("message") or f"HTTP {resp.status_code}",
                body.get("code") or resp.status_code,
            )
        return body["data"]


client = BusinessApiClient()


# ==================== 订单 ====================

@tool("query_orders", parse_docstring=True)
def query_orders(order_no: str | None = None,
                 status: str | None = None,
                 keyword: str | None = None,
                 date_from: str | None = None,
                 date_to: str | None = None,
                 currency: str | None = None,
                 page: int = 1,
                 page_size: int = 20) -> dict:
    """查询订单列表。按条件过滤,支持订单号/状态/关键词模糊搜索。

    Args:
        order_no: 订单号,格式 CE+12位数字,支持模糊匹配(可输部分单号)
        status: 订单状态枚举: PENDING_PAYMENT(待支付)/PAID(已支付)/
                SHIPPED(已发货)/COMPLETED(已完成)/CANCELLED(已取消)/
                REFUNDING(退款中)/REFUNDED(已退款)
        keyword: 模糊搜索订单号/客户名/邮箱
        page: 页码,默认 1;
        page_size: 每页条数,默认 20,最大 100
        date_from: 订单创建时间开始,格式 YYYY-MM-DD(北京时间)
        date_to: 订单创建时间结束,格式 YYYY-MM-DD(北京时间)
        currency: 币种,如 USD/EUR,大写

    Returns:
        {items: [订单摘要], total, page, page_size}
    """
    try:
        return client.get('/orders', {
            'order_no': order_no,
            'status': status,
            'keyword': keyword,
            'date_from': date_from,
            'date_to': date_to,
            'currency': currency,
            'page': page,
            'page_size': page_size,
        })
    except (httpx.HTTPError, BusinessError) as e:
        return {"error": f"查询订单失败: {e}"}


@tool("get_order", parse_docstring=True)
def get_order(order_no: str) -> dict:
    """查询单个订单详情(含商品明细、时间线、折合人民币金额)。

    Args:
        order_no: 订单号,格式 CE+12位数字

    Returns:
        订单完整信息: status/金额/客户/items/shipping_address/timeline
    """
    try:
        return client.get(f'/orders/{order_no}')
    except (httpx.HTTPError, BusinessError) as e:
        return {"error": f"查询订单详情失败: {e}"}


@tool("create_order", parse_docstring=True)
def create_order(customer_id: int, currency: str,
                 items: list[dict], shipping_address: dict) -> dict:
    """创建订单(写操作,事务扣库存)。下单成功后订单状态为待支付。

    Args:
        customer_id: 客户ID,整数(可从 get_customer 获得)
        currency: 币种,如 USD
        items: 商品列表,每项 {sku: 商品编码, quantity: 数量(>=1)}
        shipping_address: 收货地址,字段:
            recipient_name(收件人)/phone(电话)/country(国家,两位码如 US)/
            city(城市)/address_line1(街道地址)/postal_code(邮编)/
            state(州/省,可选)

    Returns:
        创建成功的订单详情
    """
    try:
        return client.post('/orders', {
            'customer_id': customer_id,
            'currency': currency,
            'items': items,
            'shipping_address': shipping_address,
        })
    except (httpx.HTTPError, BusinessError) as e:
        return {"error": f"创建订单失败: {e}"}


@tool("update_order_address", parse_docstring=True)
def update_order_address(order_no: str, address: dict) -> dict:
    """修改订单收货地址(写操作,仅未发货订单可改)。

    Args:
        order_no: 订单号,格式 CE+12位数字
        address: 新地址,字段同 create_order 的 shipping_address:
            recipient_name/phone/country/city/address_line1/postal_code,
            state 可选

    Returns:
        更新后的订单信息
    """
    try:
        return client.post(f'/orders/{order_no}/address', json=address)
    except (httpx.HTTPError, BusinessError) as e:
        return {"error": f"修改收货地址失败: {e}"}


@tool("cancel_order", parse_docstring=True)
def cancel_order(order_no: str) -> dict:
    """取消订单(写操作,仅待支付 PENDING_PAYMENT 状态可取消)。

    Args:
        order_no: 订单号,格式 CE+12位数字

    Returns:
        取消后的订单信息
    """
    try:
        return client.post(f'/orders/{order_no}/cancel')
    except (httpx.HTTPError, BusinessError) as e:
        return {"error": f"取消订单失败: {e}"}


@tool("get_tracking", parse_docstring=True)
def get_tracking(order_no: str) -> dict:
    """查询订单物流轨迹。

    Args:
        order_no: 订单号,格式 CE+12位数字

    Returns:
        {order_no, tracking_no, logistics_status, logistics_label, timeline}
        logistics_status 枚举: PENDING(待揽收)/IN_TRANSIT(运输中)/
        IN_CUSTOMS(清关中)/OUT_FOR_DELIVERY(派送中)/DELIVERED(已签收)
    """
    try:
        return client.get(f'/orders/{order_no}/tracking')
    except (httpx.HTTPError, BusinessError) as e:
        return {"error": f"查询物流轨迹失败: {e}"}


# ==================== 商品 ====================

@tool("query_products", parse_docstring=True)
def query_products(sku: str | None = None,
                   keyword: str | None = None,
                   category: str | None = None,
                   status: str | None = None,
                   page: int = 1,
                   page_size: int = 20) -> dict:
    """查询商品列表。支持 SKU/名称关键词/类目/状态过滤。

    Args:
        sku: 商品编码模糊匹配,如 SKU-1028
        keyword: 商品名称模糊搜索
        category: 商品类目
        status: 商品状态(如 ON_SALE/OFF_SALE,以实际返回为准)
        page: 页码,默认 1; page_size: 每页条数,默认 20,最大 100

    Returns:
        {items: [...], total, page, page_size}
    """
    try:
        return client.get('/products', {
            'sku': sku,
            'keyword': keyword,
            'category': category,
            'status': status,
            'page': page,
            'page_size': page_size,
        })
    except (httpx.HTTPError, BusinessError) as e:
        return {"error": f"查询商品失败: {e}"}


@tool("get_product", parse_docstring=True)
def get_product(sku: str) -> dict:
    """查询单个商品详情(含库存)。

    Args:
        sku: 商品编码,如 SKU-1028

    Returns:
        商品完整信息
    """
    try:
        return client.get(f'/products/{sku}')
    except (httpx.HTTPError, BusinessError) as e:
        return {"error": f"查询商品详情失败: {e}"}


# ==================== 客户 ====================

@tool("get_customer", parse_docstring=True)
def get_customer(customer_id: int) -> dict:
    """查询客户详情(手机号已脱敏)及消费统计。

    Args:
        customer_id: 客户ID,整数

    Returns:
        {id, name, email, phone(脱敏), country, currency,
         stats: {order_count, total_spent_cny, refund_related_count}}
    """
    try:
        return client.get(f'/customers/{customer_id}')
    except (httpx.HTTPError, BusinessError) as e:
        return {"error": f"查询客户失败: {e}"}


# ==================== 退款 ====================

@tool("apply_refund", parse_docstring=True)
def apply_refund(order_no: str, reason: str, amount: float | None = None) -> dict:
    """提交退款申请(写操作,提交后进入待审批 pending,由管理员人工审批)。

    Args:
        order_no: 订单号,格式 CE+12位数字
        reason: 退款原因,必填,500字以内
        amount: 退款金额(可选),不传默认全额;单位与订单币种一致

    Returns:
        退款单信息 {refund_no, status(pending), amount, reason, ...}
    """
    try:
        payload = {'reason': reason}
        if amount is not None:
            payload['amount'] = amount
        return client.post(f'/orders/{order_no}/refunds', json=payload)
    except (httpx.HTTPError, BusinessError) as e:
        return {"error": f"提交退款申请失败: {e}"}


@tool("query_refunds", parse_docstring=True)
def query_refunds(refund_no: str | None = None,
                  status: str | None = None,
                  order_no: str | None = None,
                  page: int = 1,
                  page_size: int = 20) -> dict:
    """查询退款单列表。

    Args:
        refund_no: 退款单号,支持模糊匹配(可输部分单号)
        status: 退款状态(小写): pending(待审批)/approved(已通过)/rejected(已驳回)
        order_no: 按订单号过滤,格式 CE+12位数字
        page: 页码,默认 1; page_size: 每页条数,默认 20,最大 100

    Returns:
        {items: [退款单], total, page, page_size}
    """
    try:
        return client.get('/refunds', {
            'refund_no': refund_no,
            'status': status,
            'order_no': order_no,
            'page': page,
            'page_size': page_size,
        })
    except (httpx.HTTPError, BusinessError) as e:
        return {"error": f"查询退款单失败: {e}"}


# ==================== 报表任务 ====================

@tool("create_task", parse_docstring=True)
def create_task(task_type: str, params: dict | None = None) -> dict:
    """创建异步报表任务(写操作)。任务后台执行,用 get_task 查询结果。

    Args:
        task_type: 任务类型枚举:
            report:monthly_sales(月度销售报表)
            report:refund_rate(退款率报表)
            export:orders(订单导出)
        params: 任务参数(可选),如 {"month": "2026-08"} 或 {"date_from": "...", "date_to": "..."}

    Returns:
        任务信息 {task_no, type, status(pending), params, ...}
    """
    try:
        return client.post('/tasks', {
            'type': task_type,
            'params': params or {},
        })
    except (httpx.HTTPError, BusinessError) as e:
        return {"error": f"创建任务失败: {e}"}


@tool("get_task", parse_docstring=True)
def get_task(task_no: str) -> dict:
    """查询异步任务状态与结果摘要。

    Args:
        task_no: 任务编号

    Returns:
        {task_no, type, status: pending(排队中)/running(执行中)/
         success(成功)/failed(失败), result 等}
    """
    try:
        return client.get(f'/tasks/{task_no}')
    except (httpx.HTTPError, BusinessError) as e:
        return {"error": f"查询任务失败: {e}"}


@tool("download_task", parse_docstring=True)
def download_task(task_no: str) -> dict:
    """获取报表/导出任务的下载链接(CSV)。任务须 success 且有产物。
    Args:
        task_no: 任务编号

    Returns:
        {download_url, ...} 或 {error: 原因}。URL 可直接复制到浏览器下载。
    """
    try:
        info = client.get(f'/tasks/{task_no}')
    except (httpx.HTTPError, BusinessError) as e:
        return {"error": f"查询任务失败: {e}"}
    if info.get('status') != 'success' or not info.get('result_path'):
        return {
            "task_no": task_no,
            "status": info.get('status', '?'),
            "status_label": info.get('status_label', ''),
            "hint": "任务未完成或无产物,暂不能下载",
        }
    # 下载链接: 对外入口 + query token(浏览器无法带 Authorization 头)
    url = f"{config.PUBLIC_API_BASE}/tasks/{task_no}/download?token={config.BIZ_API_TOKEN_AGENT}"
    return {
        "task_no": task_no,
        "status": "success",
        "download_url": url,
        "hint": "下载链接(复制到浏览器或直接点击): " + url,
    }

if __name__ == '__main__':
    import json
    # 只读接口冒烟测试(工具对象用 .invoke 调用)
    cases = [
        ("get_order", get_order, {"order_no": "CE202608241024"}),
        ("get_tracking", get_tracking, {"order_no": "CE202608241024"}),
        ("query_products", query_products, {"keyword": "Hair Dryer", "page_size": 2}),
        ("get_customer", get_customer, {"customer_id": 79210}),
        ("query_refunds", query_refunds, {}),
    ]
    for name, fn, kwargs in cases:
        res = fn.invoke(kwargs)
        ok = "error" not in res
        print(f"[{'OK' if ok else 'FAIL'}] {name}: {json.dumps(res, ensure_ascii=False)[:100]}")