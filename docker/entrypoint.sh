#!/bin/bash
set -e

# Render injects $PORT and expects the app to listen on it.
PORT="${PORT:-80}"

sed -ri "s/^Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/\*:80/*:${PORT}/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
