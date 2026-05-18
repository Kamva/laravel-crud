<?php

namespace Kamva\Crud\Columns;

use Closure;

/**
 * Reusable column renderer helpers for {@see \Kamva\Crud\CRUDController::addColumn()}.
 *
 * Each method returns a `Closure` that the column container will invoke
 * with the row. Use them in place of inline closures when you want a
 * standard badge / link / boolean / truncated / date column.
 *
 * Example:
 *
 *     use Kamva\Crud\Columns\Renderers as R;
 *
 *     $this->addColumn('Status', R::badge('status', null, ['color' => 'success']));
 *     $this->addColumn('Name',   R::link('name', fn ($r) => route('crud.lead.show', $r)));
 *     $this->addColumn('Active', R::boolean('is_active'));
 *     $this->addColumn('Bio',    R::truncate('bio', 80));
 *     $this->addColumn('Created', R::date('created_at', 'Y-m-d'));
 *
 * Designed to integrate non-breakingly: the existing `addColumn($title, $value)`
 * signature already accepts a Closure for `$value`, so these are drop-in.
 *
 * Note on escaping: each renderer returns HTML — the caller MUST be
 * confident that their list view template renders column values without
 * extra escaping (DataTables and similar table renderers don't escape by
 * default). User-supplied data is escaped within the renderer.
 */
final class Renderers
{
    /**
     * Render a Bootstrap-style badge. Accepts either a model attribute name
     * or a labeller closure that derives the visible text from the row.
     *
     * @param string|Closure      $attrOrLabel Attribute name or fn(row)=>label.
     * @param array<string,mixed> $opts {
     *     @var string $color  One of: secondary, primary, success, danger,
     *                         warning, info, light, dark. Default: secondary.
     *     @var string $class  Extra CSS classes appended to the badge.
     *     @var Closure $colorBy Optional fn(row)=>color, evaluated per row to
     *                          pick a color dynamically (e.g. from a status).
     * }
     */
    public static function badge($attrOrLabel, array $opts = []): Closure
    {
        return function ($row) use ($attrOrLabel, $opts) {
            $label = self::resolve($row, $attrOrLabel);
            if ($label === null || $label === '') return '—';

            $colorBy = $opts['colorBy'] ?? null;
            $color   = $colorBy instanceof Closure
                ? $colorBy($row)
                : ($opts['color'] ?? 'secondary');

            $extra = $opts['class'] ?? '';
            $classes = trim('badge badge-' . $color . ' ' . $extra);

            return '<span class="' . e($classes) . '">' . e((string) $label) . '</span>';
        };
    }

    /**
     * Render an anchor whose href is derived from the row and whose label is
     * the named attribute (or a labeller closure).
     *
     * @param string|Closure $attrOrLabel Attribute name or fn(row)=>label.
     * @param Closure        $hrefBuilder fn(row)=>string; required.
     * @param array<string,mixed> $opts {
     *     @var string $class  CSS class for the <a>.
     *     @var string $target Set 'blank' to open in a new tab.
     *     @var string $title  Hover title (default: '').
     * }
     */
    public static function link($attrOrLabel, Closure $hrefBuilder, array $opts = []): Closure
    {
        return function ($row) use ($attrOrLabel, $hrefBuilder, $opts) {
            $label = self::resolve($row, $attrOrLabel);
            if ($label === null || $label === '') return '—';

            $href   = (string) $hrefBuilder($row);
            $class  = $opts['class']  ?? '';
            $title  = $opts['title']  ?? '';
            $target = isset($opts['target']) && $opts['target'] === 'blank'
                ? ' target="_blank" rel="noopener"'
                : '';

            return '<a href="' . e($href) . '"'
                . ($class ? ' class="' . e($class) . '"' : '')
                . ($title ? ' title="' . e($title) . '"' : '')
                . $target . '>' . e((string) $label) . '</a>';
        };
    }

    /**
     * Render a boolean as a check / cross. Accepts attribute name or a
     * predicate closure.
     */
    public static function boolean($attrOrPredicate, array $opts = []): Closure
    {
        $yes = $opts['yes'] ?? '✓';
        $no  = $opts['no']  ?? '—';
        return function ($row) use ($attrOrPredicate, $yes, $no) {
            $val = self::resolve($row, $attrOrPredicate);
            return $val ? $yes : $no;
        };
    }

    /**
     * Truncate a long text column. Adds an ellipsis when truncated. Output
     * is HTML-escaped.
     */
    public static function truncate(string $attr, int $limit = 80): Closure
    {
        return function ($row) use ($attr, $limit) {
            $val = is_object($row) ? ($row->{$attr} ?? '') : '';
            $val = (string) $val;
            if ($val === '') return '—';
            return e(mb_strimwidth($val, 0, $limit, '…'));
        };
    }

    /**
     * Format a date attribute. Accepts an attribute name. Null becomes '—'.
     */
    public static function date(string $attr, string $format = 'Y-m-d H:i'): Closure
    {
        return function ($row) use ($attr, $format) {
            $val = is_object($row) ? ($row->{$attr} ?? null) : null;
            if (empty($val)) return '—';
            if ($val instanceof \DateTimeInterface) return $val->format($format);
            try {
                return \Illuminate\Support\Carbon::parse($val)->format($format);
            } catch (\Throwable) {
                return e((string) $val);
            }
        };
    }

    /**
     * @param mixed $attrOrCallable string attribute name or Closure(row)=>mixed
     */
    private static function resolve($row, $attrOrCallable)
    {
        if ($attrOrCallable instanceof Closure) {
            return $attrOrCallable($row);
        }
        if (is_object($row) && is_string($attrOrCallable)) {
            return $row->{$attrOrCallable} ?? null;
        }
        return null;
    }
}
