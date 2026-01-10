@echo off
echo Usage: pdepend_report.bat code_path [report_path]

SET code_path=%~1
SET report_path=%~2

REM Default values if not provided
IF "%code_path%"=="" SET code_path=/wamp/www/ocallit/SqlEr
IF "%report_path%"=="" SET report_path=%code_path%/build/reports

echo Generating pdpend to %report_path%/pdepend for %code_path%

php pdepend.phar ^
  --summary-xml=%report_path%/pdepend/summary.xml ^
  --jdepend-chart=%report_path%/pdepend/jdepend.svg ^
  --overview-pyramid=%report_path%/pdepend/pyramid.svg ^
  --dependency-xml=%report_path%/pdepend/dependencies.xml ^
  --jdepend-xml=%report_path%/pdepend/dependency_log.xml ^
  --ignore=vendor,test,tests,example,exampes ^
  --coverage-report=%code_path%/build/logs/clover.xml ^
  %code_path%


SET mainNamespace="" 
SET packageNamespace=""
REM -d display_errors=1 -d display_startup_errors=1 -d error_reporting=E_ALL 
php pdepend_util/pdepend_report.php %report_path%/pdepend/ %mainNamespace% %packageNamespace%
