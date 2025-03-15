<?php
use Symfony\Component\Yaml\Yaml;

require 'vendor/autoload.php';

// Consolidate the data and queries into a class
class pageData {
    public $pageData;
    public $db;
    public $pageNumber = 0;

    function __construct($dbname = 'simplero.sqlite', $fileName = 'pageData.yaml') {
        //
        // Pull pages and query data from yaml file
        // Run the queries and store the data
        //
        $this->pageData = Yaml::parseFile($fileName);
        $this->db = new SQLite3($dbname, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
        $this->db->enableExceptions(true);
        foreach ($this->pageData as &$data)
        {
            $results = $this->db->querySingle($data['queries']['graphData']['startYearQuery'], true);
            $startYear = $results[$data['queries']['graphData']['startYearResult']];
            $endYear = date("Y");
            $endMonth = date("m");
    
            foreach ($data['queries']['overall'] as $query)
            {
                $results = $this->db->querySingle($query['query'], true);
                $data['overall'][$query['text']] = $results[$query['result']];
            }
    
            for ($year = $startYear; $year <= $endYear; $year++)
            {
                for ($month = 1; $month <= 12; $month++)
                {
                    $m = str_pad((string)$month, 2, "0", STR_PAD_LEFT);
                    $query = sprintf($data['queries']['graphData']['query'], $m, $year);
                
                    $results = $this->db->querySingle($query, true);
                    if ($results[$data['queries']['graphData']['result']] == null)
                    {
                        $results[$data['queries']['graphData']['result']] = 0;
                    }
                    $data['graphData'][$m . '/' . substr((string)$year,2)] = $results[$data['queries']['graphData']['result']];
                    if ($year == $endYear && $month == $endMonth)
                    {
                        break;
                    }
                }
            }
        }

        //
        // Generate CVV data per https://smartmarketer.com/lifetime-customer-value-velocity/
        // and add it to the data pages
        //
        // Needs total number of customer in a cohort
        // 1. Need to calculate the cumulative amount per month for each cohort
        // 2. Switch that data so it's relative to the number of months since the cohort started
        // 3. Calculate the average amount per month column of number of months since started
        //
        // There is quite a bit of consolidation I can do below, just haven't got there yet
        // It's possible that I could have done this all in SQL and used queries from the yaml
        // file to generate the data.  I'll have to think about that.
        //

        $this->db->query('DROP TABLE IF EXISTS "first_invoice_date"');
        $this->db->query('CREATE TABLE first_invoice_date AS 
                    SELECT email, time, sum(amount) AS total from invoices WHERE 1
                    GROUP BY email ORDER BY TIME;
                ');

        $cvvdata = [];
        $cohortEndMonth = date("m");
        $startYear = date("Y", strtotime("-12 months"));
        $endYear = date("Y");

        $header = [];
        $header[] = "Cohort";
        $header[] = "Size";
        for ($year = $startYear; $year <= $endYear; $year++)
        {
            for ($month = 1; $month <= 12; $month++)
            {
                $m = str_pad((string)$month, 2, "0", STR_PAD_LEFT);
                $header[] = $m . "/" . $year . " ";
                if ($year == $endYear && $month == $cohortEndMonth)
                {
                    break;
                }
            }
        }

        for ($monthsago = 12; $monthsago >= 0; $monthsago--)
        {
            $cohortMonth = date("m", strtotime("-$monthsago months"));
            $cm = str_pad((string)$cohortMonth, 2, "0", STR_PAD_LEFT);
            $cohortYear = date("Y", strtotime("-$monthsago months"));
            $m = str_pad((string)$cohortMonth, 2, "0", STR_PAD_LEFT);
            $query = sprintf(
                "select count(*) as cohortsize from 
                (select email,time from first_invoice_date 
                where strftime('%%Y',time) = '%s' AND strftime('%%m',time) = '%s' group by email);",
                $cohortYear, $cohortMonth);

            $results = $this->db->querySingle($query, true);
            if ($results['cohortsize'] == null)
            {
                $results['cohortsize'] = 0;
            }
            $cohortSize = $results['cohortsize'];

            $newdata = [];
            for ($year = $startYear; $year <= $endYear; $year++)
            {
                for ($month = 1; $month <= 12; $month++)
                {
                    $m = str_pad((string)$month, 2, "0", STR_PAD_LEFT);
                    $query = sprintf(
                        "SELECT sum(i.amount) AS total FROM invoices AS i INNER JOIN first_invoice_date AS n 
                        ON i.email = n.email WHERE 
                        STRFTIME('%%Y',i.time) = '%s' AND STRFTIME('%%m',i.time) = '%s' AND 
                        STRFTIME('%%Y',n.time) = '%s' AND STRFTIME('%%m',n.time) = '%s';",
                        $year, $m, $cohortYear, $cm);
                
                    $results = $this->db->querySingle($query, true);
                    if ($results['total'] == null)
                    {
                        $results['total'] = 0;
                    }
                    $newdata[] = array(
                        "year" => $year,
                        "month" => $month,
                        "total" => $results['total']
                    );
                    if ($year == $endYear && $month == $cohortEndMonth)
                    {
                        break;
                    }
                }
            }
            $cvvdata[] = array(
                "cohortyear" => $cohortYear,
                "cohortmonth" => $cohortMonth,
                "cohortsize" => $cohortSize,
                "data" => $newdata
            );
        }

        $tableData = [];
        foreach ($cvvdata as $cvv)
        {
            $row = [];
            $row[] = $cvv['cohortmonth'] . "/" . $cvv['cohortyear'];
            $row[] = $cvv['cohortsize'];
            foreach ($cvv['data'] as $tdata)
            {
                $row[] = $tdata['total'];
            }
            $tableData[] = $row;
        }

        $this->pageData[] = array(
            'title' => 'Cohort Revenue by Month',
            'overall' => array("placeholder" => "placeholder"),
            'header' => $header,
            'tableData' => $tableData,
        );

        foreach ($cvvdata as &$cvv)
        {
            $cumulative = 0;
            $relativedata = [];
            $startedshift = 0;
            $shiftindex = 0;
            foreach ($cvv['data'] as &$cdata)
            {
                $cdata['cumulative'] = $cumulative += $cdata['total'];
                $cumulative = $cdata['cumulative'];
                if ($cdata['year'] == $cvv['cohortyear'] && $cdata['month'] == $cvv['cohortmonth'])
                {
                    $startedshift=1;
                }
                if ($startedshift)
                {
                    $relativedata[$shiftindex++] = $cdata['cumulative'];
                }
            }
            $cvv['relativedata'] = $relativedata;
        }

        $header = [];
        $header[] = "Cohort";
        $header[] = "Size";
        for ($i = 0; $i <= 12; $i++)
        {
            $header[] = (string)$i;
        }

        $tableData = [];
        foreach ($cvvdata as &$cvv)
        {
            $row = [];
            $row[] = $cvv['cohortmonth'] . "/" . $cvv['cohortyear'];
            $row[] = $cvv['cohortsize'];
            foreach ($cvv['relativedata'] as $rdata)
            {
                $row[] = (string)$rdata;
            }
            $tableData[] = $row;
        }

        $this->pageData[] = array(
            'title' => 'Cumulative Cohort Revenue by Start Month',
            'overall' => array("placeholder" => "placeholder"),
            'header' => $header,
            'tableData' => $tableData,
        );

        foreach ($cvvdata as &$cvv)
        {
            $cumulative = 0;
            $relativedata = [];
            $startedshift = 0;
            $cvv['averagedata'] = [];
            for ($i = 0; $i <= 12; $i++)
            {
                if (!isset($cvv['relativedata'][$i]))
                {
                    $cvv['relativedata'][$i] = 0;
                }
                $cvv['averagedata'][$i] = $cvv['relativedata'][$i] / $cvv['cohortsize'];
            }
        }

        $tableData = [];
        foreach ($cvvdata as &$cvv)
        {
            $row = [];
            $row[] = $cvv['cohortmonth'] . "/" . $cvv['cohortyear'];
            $row[] = $cvv['cohortsize'];
            foreach ($cvv['averagedata'] as $adata)
            {
                $row[] = (string)round($adata,0);
            }
            $tableData[] = $row;
        }

        $this->pageData[] = array(
            'title' => 'Average Customer Revenue by Start Month',
            'overall' => array("placeholder" => "placeholder"),
            'header' => $header,
            'tableData' => $tableData,
        );

        $totalToAverage = 13;
        $acvs = [];
        $acvs[] = "ACV";
        $maxacv = 0;
        $datapoints = [];
        for ($i = 0; $i <= 8; $i++)
        {
            $monthtotal = 0;
            foreach ($cvvdata as &$cvv)
            {
                $monthtotal += $cvv['averagedata'][$i];
            }
            $monthaverage = $monthtotal / $totalToAverage;
            $totalToAverage--;
            $acvs[] = round($monthaverage,0);
            if (round($monthaverage,0) > $maxacv)
            {
                $maxacv = round($monthaverage,0);
            }
            $datapoints[] = [ $i, round($monthaverage,0) ];
        }

        $this->pageData[] = array(
            'title' => 'Customer Value Velocity',
            'overall' => array(
                "CAC: Low Risk" => $datapoints[0][1],
                "CAC: Medium Risk" => $datapoints[1][1],
                "CAC: High Risk" => $datapoints[2][1]
            ),
            'chartinfo' => array(
                "xAxisLabel" => "Months from start (0..8)",
                "yAxisLabel" => '$ACV',
                "yMax" => $maxacv
            ),
            'header' => $header,
            'chartData' => $datapoints,
        );

        $this->db->close();
        //print_r($this->pageData, false);
        //exit;
    }

    function getCurrentData()
    {
        return($this->pageData[$this->pageNumber]);
    }

    function getNextData()
    {
        $this->pageNumber++;
        if ($this->pageNumber == count($this->pageData))
        {
            $this->pageNumber = 0;
        }
        return $this->pageData[$this->pageNumber];
    }

    function getPrevData()
    {
        $this->pageNumber--;
        if ($this->pageNumber == -1)
        {
            $this->pageNumber = count($this->pageData) - 1;
        }
        return $this->pageData[$this->pageNumber];
    }

}