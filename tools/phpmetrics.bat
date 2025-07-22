@echo off

php phpmetrics.phar  ^
    --report-html=../build/reports/phpmetrics \
    --report-json=../build/reports/phpmetrics/metrics.json \
    --report-violations=../build/reports/phpmetrics/violations.xml \
    --chart-bubbles=../build/reports/phpmetrics/bubbles.svg \
    --junit=../build/reports/logs/junit.xml ^
    --coverage-clover=../build/logs/phpunit/clover.xml ^
    --phpmd-xml=build/reports/phpmd/pmd.xml ^
    --pdepend-summary-xml=../build/reports/pdepend/summary.xml ^
    ../src/

