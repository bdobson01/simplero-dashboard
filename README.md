# simplero-dashboard
#
# Usage
#   0. composer install
#   1. Grab a products csv per instructions in simpler2sqlite.php
#   2. Use simplero2sqlite.php to grab the information and put it in a local SQLite database
#      This takes a bit to run.
#        e.g. php simplero2sqlite.php --key "MY_SIMPLERO_API_KEY"
#   3. Edit pageData.yaml. You will have different category names, different membership names, different pages.
#   4. Run the tui.php script to display the pages.
#        e.g. php tui.php
#      Use the up/down arrows to scroll forward and backward through the data pages
#
# Theory
#   You can add non-programmitic (SQL-only) data pages solely in pageData.yaml
#   You can add programmatic data pages in pageData.php (see the section on CVV)
#
