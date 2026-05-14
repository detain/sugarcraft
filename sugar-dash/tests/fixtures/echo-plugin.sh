#!/bin/bash
# Echoes back JSON requests as responses
# Transforms "method" -> "response_method" and "payload" -> "result"
while IFS= read -r line; do
    echo "$line" | sed 's/"method"/"response_method"/' | sed 's/"payload"/"result"/'
done
