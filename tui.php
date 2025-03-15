<?php
//
// Map the data pages into a TUI, basic navigation and display
declare(strict_types=1);
error_reporting(E_ERROR | E_WARNING | E_PARSE);

use PhpTui\Term\Actions;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Extension\Core\Widget\BarChart\BarGroup;
use PhpTui\Tui\Extension\Core\Widget\BarChartWidget;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Canvas\Marker;
use PhpTui\Tui\Extension\Core\Widget\Chart\Axis;
use PhpTui\Tui\Extension\Core\Widget\Chart\AxisBounds;
use PhpTui\Tui\Extension\Core\Widget\Chart\DataSet;
use PhpTui\Tui\Extension\Core\Widget\Chart\GraphType;
use PhpTui\Tui\Extension\Core\Widget\ChartWidget;
use PhpTui\Tui\Text\Span;

use Symfony\Component\Yaml\Yaml;
require 'pageData.php';

require 'vendor/autoload.php';

$datasource = new pageData();

$terminal = Terminal::new();
$terminal->enableRawMode();
$terminal->execute(Actions::printString('Entering event loop, press ESC to exit'));
$terminal->execute(Actions::moveCursorNextLine());
$terminal->execute(Actions::enableMouseCapture());
$display = DisplayBuilder::default(PhpTermBackend::new($terminal))->build();

$display->clear();

try {
    $data = $datasource->getCurrentData();
    displayData($display, $data);
    // enter the event loop
    eventLoop($terminal, $display, $datasource);
} finally {
    // restore the terminal to it's previous state
    $terminal->execute(Actions::disableMouseCapture());
    $terminal->disableRawMode();
}

function displayData($display, $data)
{
    $overallrows = [];
    foreach ($data['overall'] as $key => $value) {
        $overallrows[] = TableRow::fromStrings(
            $key,
            (string)$value,
        );
    }
    if (isset($data['graphData']))
    {
        $widget = BarChartWidget::default()
            ->barWidth(5)
            ->barStyle(Style::default()->red())
            ->groupGap(5)
            ->data(
                BarGroup::fromArray($data['graphData'])->label(Line::fromString($data['queries']['graphData']['text'])),
            );
    }
    if (isset($data['tableData']))
    {
        $tableData = [];
        foreach ($data['tableData'] as $row) {
            $tableRow = [];
            foreach ($row as $cell) {
                $tableRow[] = TableCell::fromString((string)$cell);
            }
            $tableData[] = TableRow::fromCells(...$tableRow)->height(1);
        }
        $constraints = [];
        $width = 100 / count($data['header']);
        foreach ($data['header'] as $header) {
            $constraints[] = Constraint::percentage((int)$width+1);
        }
        $widget = TableWidget::default()
            ->widths(
                ...$constraints
            )
            ->header(
                TableRow::fromStrings(
                    ...$data['header']
                )->height(2)->bottomMargin(1)
            )
            ->rows(...$tableData);
    }
    if (isset($data['chartData']))
    {
        $ylabels = [];
        $ylabels[] = Span::fromString($data['chartinfo']['yAxisLabel']);
        $ylabels[] = Span::fromString((string)($data['chartinfo']['yMax']/4));
        $ylabels[] = Span::fromString((string)($data['chartinfo']['yMax']/2));
        $ylabels[] = Span::fromString((string)($data['chartinfo']['yMax']*3/4));
        $ylabels[] = Span::fromString((string)($data['chartinfo']['yMax']));
        $widget = ChartWidget::new(
                DataSet::new('Points')
                        ->data(
                            $data['chartData']
                        )
                        ->marker(Marker::Dot),
            )
                ->xAxis(
                    Axis::default()
                        ->labels(
                            Span::fromString($data['chartinfo']['xAxisLabel']),
                        )
                        ->bounds(AxisBounds::new(0, 8))
                )
                ->yAxis(
                    Axis::default()
                        ->labels(...$ylabels
                        )
                        ->bounds(AxisBounds::new(0, $data['chartinfo']['yMax']))
                        );
    }

    $display->clear();
    $display->draw(
        GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(
                Constraint::percentage(80),
                Constraint::percentage(20),
            )
            ->widgets(
                BlockWidget::default()
                    ->borders(Borders::ALL)->titles(Title::fromString($data['title']))
                    ->widget(
                        $widget
                    ),
                GridWidget::default()
                    ->direction(Direction::Vertical)
                    ->constraints(
                        Constraint::percentage(50),
                        Constraint::percentage(50),
                    )
                    ->widgets(
                        BlockWidget::default()->borders(Borders::ALL)->titles(Title::fromString('Overall Data'))->padding(Padding::all(1))
                            ->widget(
                                TableWidget::default()
                                ->widths(
                                    Constraint::percentage(50),
                                    Constraint::percentage(25),
                                )
                                ->header(
                                    TableRow::fromStrings(
                                        'Name',
                                        'Value',
                                    )->height(2)->bottomMargin(1)
                                )
                                ->rows(...$overallrows)
                            ),
                        BlockWidget::default()->borders(Borders::ALL)->titles(Title::fromString('Instructions'))->padding(Padding::all(1))
                            ->widget(
                                ParagraphWidget::fromText(
                                    Text::fromString(
                                        <<<'EOT'

                                            ⬆️ ⬇️ to change data pages
                                            ESC to exit
                                            EOT
                                    )
                            )),
                    )
            )
    );
}

function eventLoop(Terminal $terminal, $display, $pageData): void
{
    // start the loop!
    while (true) {

        // drain any events from the event buffer and process them
        while ($event = $terminal->events()->next()) {

            // events can be of different types containing different information
            if ($event instanceof CodedKeyEvent) {
                if ($event->code === KeyCode::Esc) {
                    return;
                }
                if ($event->code === KeyCode::Up) {
                    displayData($display, $pageData->getNextData());
                }
                if ($event->code === KeyCode::Down) {
                    displayData($display, $pageData->getPrevData());
                }
            }

            // most events also have modifiers so you can see if the event happened
            // with a key modifier such as CONTROL or ALT
            if ($event instanceof CharKeyEvent) {
                if ($event->char === 'c' && $event->modifiers === KeyModifiers::CONTROL) {
                    return;
                }
            }
        }
        usleep(10000);
    }
}
