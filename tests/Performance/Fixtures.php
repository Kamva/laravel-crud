<?php

namespace Kamva\Crud\Tests\Performance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kamva\Crud\Actions\Internal\BaseAction;
use Kamva\Crud\Columns\Renderers;
use Kamva\Crud\CRUDController;
use Illuminate\Contracts\Support\Renderable;
use Kamva\Crud\Fields\Internal\BaseField;

/**
 * A controller shaped like a typical consumer's: plain, relation, form-field
 * and renderer columns, a sourced select field, five row actions (so the
 * dropdown path runs) and a search filter.
 */
class BenchProductController extends CRUDController
{
    public function setup(): void
    {
        $this->setTitle('Products');
        $this->setModel(BenchProduct::class);

        $this->addColumn('Name', 'name');
        $this->addColumn('Price', 'price');
        $this->addColumn('Category', 'category.title');
        $this->addColumn('Status', 'status.field');
        $this->addColumn('Badge', Renderers::badge('status', [
            'colorBy' => fn ($row) => $row->status === 'active' ? 'success' : 'secondary',
        ]));
        $this->addColumn('Created', Renderers::date('created_at', 'Y-m-d'));

        $this->addField(BenchField::class, 'Name', 'name')->setValidation(['required']);
        $this->addField(BenchField::class, 'Price', 'price');
        $this->addField(BenchField::class, 'Status', 'status', null, [
            'active' => 'Active', 'draft' => 'Draft', 'archived' => 'Archived',
        ]);
        $this->addField(BenchField::class, 'Category', 'category_id')
            ->setSource(BenchCategory::class, 'id', 'title')
            ->observe('status', fn ($value, $field) => true);

        $this->addApiEntity('name');
        $this->addApiEntity('price');
        $this->addApiEntity('category', 'category.title');
        $this->addApiEntity('status', 'status.field');

        $this->addAction(BenchShowAction::class, 'bench.products.show');
        $this->addAction(BenchEditAction::class, 'bench.products.edit');
        $this->addAction(BenchDeleteAction::class, 'bench.products.destroy');
        $this->addAction(BenchDuplicateAction::class, 'bench.products.duplicate');
        $this->addAction(BenchArchiveAction::class, 'bench.products.archive');

        $this->addSearchField(['name'], 'q');
    }
}

class BenchProduct extends Model
{
    protected $table = 'bench_products';
    protected $guarded = [];

    public function category(): BelongsTo
    {
        return $this->belongsTo(BenchCategory::class, 'category_id');
    }
}

class BenchCategory extends Model
{
    protected $table = 'bench_categories';
    protected $guarded = [];
}

class BenchShowAction extends BaseAction
{
    public $caption    = 'Show';
    public $render     = '<i class="feather icon-eye"></i>';
    public $parameters = ['$id'];
}

class BenchEditAction extends BaseAction
{
    public $caption    = 'Edit';
    public $render     = '<i class="feather icon-edit"></i>';
    public $parameters = ['$id'];
    public $options    = ['class' => 'text-info'];
}

class BenchDeleteAction extends BaseAction
{
    public $method     = 'DELETE';
    public $caption    = 'Delete';
    public $render     = '<i class="feather icon-trash"></i>';
    public $parameters = ['$id'];
    public $options    = ['class' => 'text-danger ', 'ask' => true];
}

class BenchDuplicateAction extends BaseAction
{
    public $method     = 'POST';
    public $caption    = 'Duplicate';
    public $render     = '<i class="feather icon-copy"></i>';
    public $parameters = ['$id'];
}

class BenchArchiveAction extends BaseAction
{
    public $method     = 'PATCH';
    public $caption    = 'Archive';
    public $render     = '<i class="feather icon-archive"></i>';
    public $parameters = ['$id'];
}

/**
 * A field that renders like a typical consumer field: an input carrying the
 * current value, plus an <option> list when the field has options.
 */
class BenchField extends BaseField
{
    public function render($data = null): Renderable
    {
        $html = '<label>' . e($this->getCaption()) . '</label>';

        if ($options = $this->getOptions()) {
            $current = (string) $this->getValue($data);
            $html   .= '<select name="' . e($this->getName()) . '">';
            foreach ($options as $key => $label) {
                $html .= '<option value="' . e($key) . '"' . ((string) $key === $current ? ' selected' : '') . '>' . e($label) . '</option>';
            }
            $html .= '</select>';
        } else {
            $html .= '<input name="' . e($this->getName()) . '" value="' . e((string) $this->getValue($data)) . '">';
        }

        return new BenchHtml($html);
    }
}

class BenchHtml implements Renderable
{
    private string $html;

    public function __construct(string $html)
    {
        $this->html = $html;
    }

    public function render()
    {
        return $this->html;
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
