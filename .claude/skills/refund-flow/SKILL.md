---
name: refund-flow
description: 改退款链路任何一环时必读——申请、用户确认、管理员审批、驳回、通知推送。退款是本项目的资金类操作，受三层不变量约束，且审批逻辑在 Api 与 Web 两个控制器里各有一份、事件发布 payload 必须逐字一致，漏改一端就会出现"后台审批了但聊天端收不到通知"。用户提到退款、refund、审批、驳回、applyRefund、approveRefund、refund_events、资金操作时用这个 skill。
---

# 退款链路

**资金类操作。动任何一处之前，先确认不打破三条不变量：**

1. **Agent 只做对话与编排，不直连数据库** —— 一律经 `business-system` 的 REST API
2. **业务系统不感知 AI** —— 它不知道调用方是人还是 Agent，鉴权与业务校验一视同仁
3. **资金类操作强制 human-in-the-loop** —— 退款必须经 interrupt 确认 + 管理员审批，
   不允许 Agent 绕过确认直接调退款接口

## 全链路

两段式，**只有第一段会挂起图**：

```
用户: "帮我退 CE202608241027"
  │
  ├─ agent  graph/router.py  route_by_intent → "refund"
  │  graph/nodes.py:248 refund_node
  │    extract_refund_args（含最近 6 条上下文做指代消解）
  │    find_recent_order_no 兜底（正则 CE\d{12}）
  │    get_order 校验状态 —— PENDING_PAYMENT/CANCELLED/REFUNDED/REFUNDING 不支持
  │    needs_confirm?  金额>实付，或未填金额且实付 > REFUND_CONFIRM_THRESHOLD(500 USD)
  │       是 → nodes.py:272 interrupt({"type":"refund_confirm",...})   ★挂起
  │            WS {"type":"resume"} → Command(resume=...) 恢复（chat_server.py:229）
  │    apply_refund → POST /api/v1/orders/{no}/refunds      (api.token:agent)
  │    refund_mapper.record(refund_no → thread_id)          ← 通知靠这个反查
  │
  ├─ Laravel  Api\RefundController::store → OrderService::applyRefund
  │           创建 refunds 行，status = pending
  │
  └─ 管理员审批（后台页面，或调 admin API）
             Api\RefundController::approve / reject
             Web\RefundController::approve / reject
               → OrderService::approveRefund / rejectRefund
               → RefundEventPublisher::publish → RabbitMQ 队列 refund_events
                       │
                       └─ agent/chat/notify_bridge.py  ChatEventBridge
                            _consume → 按 refund_no 查 refund_mapper → _push
                            → WebSocket 推给对应会话
```

第二段**不挂起图** —— 提交后退款节点立即返回，审批结果靠 RabbitMQ 事件异步推回
（`nodes.py:289-297` 注释说明了这个「方案 B」）。

所以：**审批通知丢失，通常不是图的问题，是事件链断了。** 排查思路见文末。

## 改退款必须双端同步 ← 最容易踩的坑

审批逻辑在**两个控制器**里各有一份，是两个文件，不是同一份代码：

| | 申请 | 审批页 | 批准 | 驳回 |
|---|---|---|---|---|
| API | `Api\RefundController::store` | — | `::approve` | `::reject` |
| Web | `Web\OrderController::refund` | `Web\RefundController::index/show` | `::approve` | `::reject` |

注意 Web 端的**退款申请不在** `Web\RefundController`，而在 `Web\OrderController@refund`；
`Web\RefundController` 只负责审批页。

**必须逐字一致的三处：**

1. **审批调用** —— 两端都调 `OrderService::approveRefund` / `rejectRefund`。
   Web 显式传 operator `'admin'`，Api 走默认值（`OrderService.php:219` 默认也是 `'admin'`）。
2. **事件 payload** —— `RefundEventPublisher::publish([...])` 两端字段必须完全一致：
   `refund_no, order_no, result(approved|rejected), amount, currency, approved_at`。
   改一端漏改另一端 → **后台页面审批不发布事件、聊天端收不到通知**。
3. **错误处理** —— 两端都捕获 `BusinessException`：Api 返回 JSON fail，Web `back()->with('error')`。

改完做一次交叉验证：**从后台页面批准一单**，确认聊天端收到通知。
只有走页面这条路，才会执行到 Web 端那份发布代码。

## 权限

- `POST /api/v1/orders/{orderNo}/refunds`（申请）→ `api.token:agent`
- `POST /api/v1/refunds/{id}/approve` / `reject` → **`api.token:admin`，不可放宽、不可合并**
- **Agent 侧没有任何审批工具** —— `apply_refund` 只能创建 pending 单

`app/Http/Middleware/ApiTokenAuth.php` 靠中间件参数 `api.token:agent|admin` 选对应角色的 token，
用 `hash_equals` 常量时间比较。GET 请求额外允许 `?token=` query（下载链接场景；写操作仍强制 header）。

## 状态

- `app/Enums/RefundStatus.php`：`pending` / `approved` / `rejected`
- `refunds.order_status_before` —— 驳回时恢复订单状态用的原状态
- 订单侧可退款状态见 `OrderService.php:36` 的 `REFUNDABLE`

## 验证

**无自动化测试覆盖审批链路**，改动后手工走：

```bash
docker compose logs -f php-worker     # 异步任务 / 审批事件日志
docker compose logs -f chat           # Agent 服务日志
python eval/run_eval.py --category 退款售后
```

`cases.jsonl` 的「退款售后」类有 6 条，其中 `refund-01` 期望 `expect.type=interrupt`
（断言停在 `refund_confirm` 中断点）。**这批只覆盖到第一段的用户确认**，第二段管理员审批要手点。

## 通知收不到时怎么查

逐段定位，别从图开始猜：

1. `docker compose logs -f php-worker` —— 审批有没有走到 `RefundEventPublisher::publish`？
   发布失败只记 log、**不阻断审批主流程**（`:47-55`），所以日志里可能只有一行 warning。
2. `docker compose logs -f chat` —— `ChatEventBridge._consume` 有没有收到消息？
3. 查 `refund_mapper` 里有没有 `refund_no → thread_id` 映射 —— 没有映射就找不到会话，通知无处可投。
4. 检查聊天服务的 `RABBITMQ_PASSWORD` —— 以 compose 的 `environment` 为准（它会**覆盖** `env_file`）。
