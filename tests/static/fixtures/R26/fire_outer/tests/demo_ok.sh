#!/usr/bin/env bash
# 견본: 뿌리 쪽은 깨끗하다
grep -c 'add_header X-Demo-Echo $http_x_demo' /srv/demo.example.invalid/sites/demo.conf
