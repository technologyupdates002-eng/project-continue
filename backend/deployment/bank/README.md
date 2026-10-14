# Bank Integration Testing

## Purpose

This directory contains artifacts for testing and validating the Co-operative Bank integration in the KHWWC Welfare System.

## Postman Collection

**File:** `postman/KIRINYAGA_HEALTH_CARE_WORKERS_WELFARE.postman_collection.json`

- Supplied Co-operative Bank API collection
- Contains **TEST/SANDBOX credentials only**
- Must NOT be exposed through the web server
- Must NOT have its credentials copied into application code or production `.env`

## Production Credentials

Production bank credentials must be configured separately on the production server through the `.env` file:

```dotenv
COOP_CONSUMER_KEY=
COOP_CONSUMER_SECRET=
COOP_ACCOUNT_NUMBER=
```

Do NOT commit production credentials to version control.

## Important

- Do not expose this directory through the web server.
- This collection is a deployment/testing artifact only.
- All bank requests must be performed server-side by Laravel.
- The browser must never directly communicate with the bank.
