---
name: api-design
description: REST conventions, JSON:API patterns, authentication flows, error handling. Use when designing APIs, implementing endpoints, handling authentication, or structuring JSON responses.
license: MIT
metadata:
  author: api-community
  version: "1.0.0"
  protocol: REST/JSON:API
  phpVersion: "8.1 - 8.5"
---

# API Design

REST conventions, JSON:API patterns, authentication flows, and error handling for building maintainable APIs.

## When to Apply

- Designing or reviewing API endpoints
- Implementing REST controllers
- Structuring JSON responses
- Handling API authentication
- Error handling and validation

## REST Conventions

### URL Structure

Use nouns, not verbs; plural for collections:

```
# GOOD
GET    /api/users          - List users
GET    /api/users/123      - Get user 123
POST   /api/users          - Create user
PUT    /api/users/123      - Update user 123
DELETE /api/users/123      - Delete user 123

# BAD
GET    /api/getUsers
GET    /api/getUser/123
POST   /api/createUser
POST   /api/user/delete/123
```

### HTTP Methods

Use correct methods for operations:

| Method | Purpose | Idempotent | Body |
|--------|---------|------------|------|
| GET | Retrieve resource | Yes | No |
| POST | Create resource | No | Yes |
| PUT | Replace resource | Yes | Yes |
| PATCH | Partial update | No | Yes |
| DELETE | Remove resource | Yes | Optional |

### Nested Resources

Limit nesting to 2 levels:

```
# Acceptable
/api/users/123/orders

# Avoid
/api/users/123/orders/456/items/789
```

For deeper relations, use query parameters or direct resource access:

```
GET /api/orders?user_id=123&status=pending
GET /api/items?order_id=456
```

## JSON:API Patterns

### Response Structure

Follow JSON:API specification for consistency:

```json
{
  "data": {
    "type": "users",
    "id": "123",
    "attributes": {
      "name": "Jane Smith",
      "email": "jane@example.com",
      "created_at": "2024-01-15T10:30:00Z"
    },
    "relationships": {
      "orders": {
        "links": {
          "related": "/api/users/123/orders"
        }
      }
    },
    "links": {
      "self": "/api/users/123"
    }
  },
  "meta": {
    "copyright": "2024 Example Corp"
  }
}
```

### Collection Response

```json
{
  "data": [
    {
      "type": "users",
      "id": "123",
      "attributes": {
        "name": "Jane Smith",
        "email": "jane@example.com"
      }
    },
    {
      "type": "users",
      "id": "456",
      "attributes": {
        "name": "John Doe",
        "email": "john@example.com"
      }
    }
  ],
  "meta": {
    "total": 2,
    "page": 1,
    "per_page": 20
  },
  "links": {
    "self": "/api/users?page=1",
    "next": "/api/users?page=2",
    "prev": null
  }
}
```

### Sparse Fieldsets

Allow clients to request specific fields:

```
GET /api/users?fields[users]=name,email
GET /api/orders?include=user:限name,email&fields[orders]=id,total
```

## Error Handling

### Error Response Format

Return consistent error structure:

```json
{
  "error": {
    "status": 422,
    "code": "VALIDATION_ERROR",
    "title": "Validation Failed",
    "detail": "The given data was invalid.",
    "source": {
      "pointer": "/data/attributes/email",
      "parameter": "email"
    },
    "meta": {
      "errors": [
        {
          "field": "email",
          "message": "The email field must be a valid email address."
        }
      ]
    }
  }
}
```

### HTTP Status Codes

Use appropriate status codes:

| Code | Meaning | Use Case |
|------|---------|----------|
| 200 | OK | Successful GET, PUT, PATCH |
| 201 | Created | Successful POST |
| 204 | No Content | Successful DELETE |
| 400 | Bad Request | Malformed request |
| 401 | Unauthorized | Missing/invalid auth |
| 403 | Forbidden | Authenticated but not allowed |
| 404 | Not Found | Resource doesn't exist |
| 409 | Conflict | Duplicate, state conflict |
| 422 | Unprocessable | Validation failed |
| 429 | Too Many Requests | Rate limit exceeded |
| 500 | Server Error | Unexpected error |

### Validation Errors

Return field-level validation errors:

```json
{
  "error": {
    "status": 422,
    "code": "VALIDATION_ERROR",
    "title": "The given data was invalid.",
    "detail": "One or more fields had validation errors.",
    "meta": {
      "errors": [
        {
          "field": "name",
          "code": "required",
          "message": "The name field is required."
        },
        {
          "field": "email",
          "code": "email",
          "message": "The email must be a valid email address."
        },
        {
          "field": "password",
          "code": "min_length",
          "message": "The password must be at least 8 characters."
        }
      ]
    }
  }
}
```

## Authentication Flows

### Token-Based Auth (JWT)

Issue and validate tokens:

```php
// POST /api/auth/login
public function login(LoginRequest $request): JsonResponse
{
    $user = $this->userRepository->findByEmail($request->email);

    if (!$user || !Hash::verify($request->password, $user->password)) {
        return $this->errorResponse([
            'status' => 401,
            'code' => 'INVALID_CREDENTIALS',
            'title' => 'Invalid credentials',
        ], 401);
    }

    $token = $this->jwtService->issue([
        'sub' => $user->id,
        'exp' => time() + 3600,
    ]);

    return $this->successResponse([
        'token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    ]);
}
```

### Token Refresh Flow

Support token refresh:

```php
// POST /api/auth/refresh
public function refresh(RefreshRequest $request): JsonResponse
{
    try {
        $payload = $this->jwtService->verify($request->token);
    } catch (TokenExpiredException $e) {
        return $this->errorResponse([
            'status' => 401,
            'code' => 'TOKEN_EXPIRED',
            'title' => 'Token has expired',
        ], 401);
    }

    $newToken = $this->jwtService->refresh($payload);

    return $this->successResponse([
        'token' => $newToken,
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    ]);
}
```

### API Key Authentication

For server-to-server communication:

```php
// Middleware: Validate API key
public function handle(Request $request, Closure $next): Response
{
    $apiKey = $request->header('X-API-Key');

    if (!$apiKey) {
        return $this->errorResponse([
            'status' => 401,
            'code' => 'MISSING_API_KEY',
            'title' => 'API key is required',
        ], 401);
    }

    $client = $this->clientRepository->findByApiKey($apiKey);

    if (!$client) {
        return $this->errorResponse([
            'status' => 401,
            'code' => 'INVALID_API_KEY',
            'title' => 'Invalid API key',
        ], 401);
    }

    $request->attributes->set('client', $client);

    return $next($request);
}
```

## Rate Limiting

### Implementation

Protect APIs with rate limiting:

```php
// Apply rate limit to routes
$router->group(['prefix' => 'api', 'middleware' => 'throttle:60,1'], function ($router) {
    // Standard endpoints: 60 requests per minute
});

$router->group(['prefix' => 'api', 'middleware' => 'throttle:1000,1'], function ($router) {
    // Bulk endpoints: 1000 requests per minute
});
```

### Rate Limit Headers

Return rate limit info in responses:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1642089600
```

### Rate Limit Exceeded Response

```json
{
  "error": {
    "status": 429,
    "code": "RATE_LIMIT_EXCEEDED",
    "title": "Too many requests",
    "detail": "Rate limit exceeded. Please retry after 30 seconds.",
    "meta": {
      "retry_after": 30
    }
  }
}
```

## Pagination

### Cursor-Based Pagination

Prefer cursor-based for large datasets:

```json
{
  "data": [...],
  "meta": {
    "per_page": 20,
    "next_cursor": "eyJpZCI6MTIzfQ==",
    "has_more": true
  }
}
```

### Offset Pagination

Acceptable for smaller, static datasets:

```
GET /api/users?page=2&per_page=20
```

## API Versioning

### URL Path Versioning

Most explicit and cacheable:

```
/api/v1/users
/api/v2/users
```

### Header Versioning

Less visible but cleaner URLs:

```
Accept: application/vnd.api+json; version=2
```

## Output Format

When auditing API code, output findings in this format:

```
file:line - [category] Description of issue
```

Example:
```
app/Http/Controllers/UserController.php:42 - [rest] Use nouns in URL, not verbs
app/Http/Controllers/OrderController.php:28 - [error] Missing error response structure
app/Services/AuthService.php:15 - [auth] JWT missing expiration claim
```
