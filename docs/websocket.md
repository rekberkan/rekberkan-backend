# WebSocket Events & Channel Authentication

## Overview
Rekberkan backend uses Laravel Echo with Pusher protocol for real-time WebSocket communication. All channels enforce tenant isolation and participant verification.

## Authentication

### Connection Authentication
Clients must provide a valid JWT token when establishing WebSocket connection:

```javascript
const echo = new Echo({
    broadcaster: 'pusher',
    key: process.env.PUSHER_APP_KEY,
    cluster: process.env.PUSHER_APP_CLUSTER,
    encrypted: true,
    auth: {
        headers: {
            Authorization: `Bearer ${jwtToken}`,
            'X-Tenant-ID': tenantId
        }
    }
});
```

### Channel Authorization
Private and presence channels require authorization. The backend verifies:
1. JWT token validity
2. Tenant ID match
3. User permission to access the specific resource

## Channel Naming Convention

### Pattern
```
tenant.{tenant_id}.{resource}.{resource_id}[.{sub_resource}]
```

### Examples
- `tenant.1.escrow.42.chat` - Chat for escrow #42 in tenant #1
- `tenant.1.user.15` - Personal channel for user #15 in tenant #1

## Available Channels

### 1. Escrow Chat Channel
**Channel**: `tenant.{tenant_id}.escrow.{escrow_id}.chat`

**Authorization**: 
- Buyer of the escrow
- Seller of the escrow
- Admin (only if escrow status is DISPUTED)

**Events**:
- `message.sent` - New chat message

**Payload**:
```json
{
    "id": 123,
    "escrow_id": 42,
    "sender_type": "App\\Models\\User",
    "sender_id": 15,
    "body": "Hello, when will you ship?",
    "created_at": "2026-01-11T15:30:00+07:00"
}
```

**Usage**:
```javascript
echo.channel(`tenant.1.escrow.42.chat`)
    .listen('.message.sent', (e) => {
        console.log('New message:', e.body);
        appendMessageToUI(e);
    });
```

### 2. User Personal Channel
**Channel**: `tenant.{tenant_id}.user.{user_id}`

**Authorization**: Only the user themselves

**Events**:
- `notification.created` - New in-app notification

**Payload**:
```json
{
    "id": 456,
    "type": "ESCROW_STATUS_CHANGED",
    "title": "Escrow Status Updated",
    "body": "Escrow #42 status changed to COMPLETED",
    "metadata": {
        "escrow_id": 42,
        "new_status": "COMPLETED"
    },
    "created_at": "2026-01-11T15:30:00+07:00"
}
```

**Usage**:
```javascript
echo.channel(`tenant.1.user.15`)
    .listen('.notification.created', (e) => {
        console.log('New notification:', e.title);
        showNotificationToast(e);
    });
```

### 3. Escrow Status Channel (Future)
**Channel**: `tenant.{tenant_id}.escrow.{escrow_id}`

**Authorization**: Buyer or Seller

**Events**:
- `status.changed` - Escrow status transition

## Security Considerations

### PII Protection
- Chat message bodies are transmitted but **never logged** in application logs
- Only message IDs and metadata are logged for audit purposes
- User-agent and IP are captured at creation time, not in broadcasts

### Tenant Isolation
- All channels enforce `tenant_id` match
- Cross-tenant subscriptions are blocked at authorization layer
- RLS policies apply to all database queries

### Rate Limiting
WebSocket connections are rate-limited:
- **Connection attempts**: 10 per minute per IP
- **Message sending**: 30 per minute per user
- **Channel subscriptions**: 20 per connection

### Replay Attack Prevention
- JWT tokens have 1-hour expiration
- Refresh tokens required for long-lived connections
- WebSocket connections auto-disconnect on token expiry

## Error Handling

### Connection Errors
```javascript
echo.connector.pusher.connection.bind('error', (error) => {
    if (error.type === 'AuthError') {
        // Token expired or invalid - redirect to login
        window.location.href = '/login';
    }
});
```

### Subscription Errors
```javascript
echo.channel('tenant.1.escrow.42.chat')
    .error((error) => {
        console.error('Subscription failed:', error);
        // Show user-friendly message
    });
```

## Testing WebSocket Locally

### Using Laravel Echo Server (Development)
```bash
npm install -g laravel-echo-server
laravel-echo-server init
laravel-echo-server start
```

### Using Pusher (Staging/Production)
Configure `.env`:
```
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=your_app_id
PUSHER_APP_KEY=your_app_key
PUSHER_APP_SECRET=your_app_secret
PUSHER_APP_CLUSTER=ap1
```

## Monitoring

### Metrics to Track
- Active WebSocket connections per tenant
- Message throughput (messages/second)
- Failed authorization attempts
- Average message delivery latency

### Logging
All WebSocket events are logged to:
- `audit_log` table (authorization attempts)
- `security_events` table (failed auth, rate limit violations)
- Application logs (connection/disconnection events)

## Future Enhancements

1. **Typing Indicators**: Broadcast when users are typing in chat
2. **Online Presence**: Show which participants are currently online
3. **Read Receipts**: Track when messages are read
4. **Admin Dashboard Channel**: Real-time metrics for administrators
5. **System Announcement Channel**: Broadcast platform-wide notifications
