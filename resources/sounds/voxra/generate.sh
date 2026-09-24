#!/usr/bin/env bash
# Regenerates Voxra's call-screening rejection prompts (voxragtm#84), played by
# resources/lua/voxra_screen_call.lua. Voice: Telnyx Ultra "Alistair - Composed
# Consultant" (en-GB), the Voxra assistant's default voice
# (TelnyxConvaiService::DEFAULT_VOICE). Needs TELNYX_TOKEN and ffmpeg.
set -euo pipefail
cd "$(dirname "$0")"
VOICE="Telnyx.Ultra.c8f7835e-28a3-4f0c-80d7-c1302ac62aae"
declare -A TEXT=(
  [rejected]="Sorry, this number can't take your call right now. Goodbye."
  [anonymous]="Sorry, this number doesn't accept calls from withheld numbers. Please call again with your number shown. Goodbye."
)
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
for name in "${!TEXT[@]}"; do
  curl -sf -o "$tmp/$name.mp3" -X POST https://api.telnyx.com/v2/text-to-speech/speech \
    -H "Authorization: Bearer $TELNYX_TOKEN" -H "Content-Type: application/json" \
    -d "{\"text\":\"${TEXT[$name]}\",\"voice\":\"$VOICE\",\"output_type\":\"binary_output\"}"
  for rate in 8000 16000; do
    mkdir -p "$rate"
    ffmpeg -v error -y -i "$tmp/$name.mp3" -af "volume=-2dB,apad=pad_dur=0.4" \
      -ar "$rate" -ac 1 -c:a pcm_s16le "$rate/voxra-call-$name.wav"
  done
done
