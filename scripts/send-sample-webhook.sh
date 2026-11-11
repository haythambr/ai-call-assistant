#!/usr/bin/env bash
#
# Sends a signed sample call to an account's webhook, exactly like a voice provider would.
# Usage: scripts/send-sample-webhook.sh [base-url] [account-email]
# The account defaults to the demo account from the seeder.

set -euo pipefail
cd "$(dirname "$0")/.."

email="${2:-demo@callassistant.test}"
token="$(php artisan tinker --execute="echo App\\Models\\User::where('email', '$email')->value('webhook_token');" | tail -n 1)"

if [ -z "$token" ]; then
    echo "No account with the email $email. Sign up first, or run php artisan db:seed."
    exit 1
fi

url="${1:-http://localhost:8000}/api/webhooks/calls/$token"
secret="$(grep -E '^CALL_WEBHOOK_SECRET=' .env | cut -d= -f2- | tr -d '"')"

if [ -z "$secret" ]; then
    echo "Set CALL_WEBHOOK_SECRET in .env first."
    exit 1
fi

body=$(cat <<JSON
{
  "call_id": "call_$(date +%s)",
  "direction": "inbound",
  "duration_seconds": 131,
  "started_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "caller": { "name": "Omar Saleh", "phone": "+1 312 555 0119", "email": "omar@example.com" },
  "transcript": [
    { "role": "agent",  "text": "Bright Smile Dental, this is Ava. How can I help?" },
    { "role": "caller", "text": "Hi, can I book an appointment for a check-up next Monday at 3 PM?" },
    { "role": "agent",  "text": "Next Monday at 3 PM works. I have you down for a check-up." },
    { "role": "caller", "text": "Great, thank you." }
  ]
}
JSON
)

signature="sha256=$(printf '%s' "$body" | openssl dgst -sha256 -hmac "$secret" | sed 's/^.* //')"

curl -sS -X POST "$url" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -H "X-Signature: $signature" \
    --data "$body"
echo
