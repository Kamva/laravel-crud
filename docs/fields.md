# Fields

Fields declare what the create / edit forms render and how submitted data
is written back to the model. Register fields in `setup()` via
`$this->addField($class, $caption, $name, $value = null, $options = [])`.

```php
use App\KamvaCrud\Fields\Text;
use App\KamvaCrud\Fields\Select;
use App\KamvaCrud\Fields\TextArea;

$this->addField(Text::class, 'Name', 'name')->setValidation(['required']);
$this->addField(Text::class, 'Email', 'email')->setValidation(['nullable', 'email']);
$this->addField(Select::class, 'Status', 'status')->setOptions([
    'open' => 'Open',
    'closed' => 'Closed',
])->setValidation(['required']);
$this->addField(TextArea::class, 'Notes', 'notes');
```

## Built-in field classes

Application projects typically extend these via thin app-side subclasses
(see `app/KamvaCrud/Fields/` in the host project). The package itself
defines:

- `Text` — single-line text input
- `TextArea` — multi-line
- `Select` — single or multi-select dropdown (`->setMultiple()`)
- `Hidden` — hidden form input
- `CheckBox` — boolean
- `Password` — masked input (re-hashed on save)
- `File` — file upload (uses Laravel's filesystem)
- `Image` — file upload + image validation
- `ReadOnlyText` — text-only display (no input)
- `PersianDate` — Jalali date picker, stored as Carbon
- `PlainTextArea` — text area without rich-text plumbing
- `HR` — visual separator

## Validation

Each field has `setValidation(array $rules)` that accepts Laravel
validation rules. Multiple calls merge. The framework validates at
save-time before writing to the model:

```php
$this->addField(Text::class, 'Email', 'email')
    ->setValidation(['required', 'email', Rule::unique('users', 'email')]);
```

For a shorthand requiring a field:

```php
$this->addRequiredField(Text::class, 'Name', 'name');
```

## Custom field types

Subclass `Kamva\Crud\Fields\Internal\BaseField` and implement at minimum:

```php
class MyField extends BaseField implements FieldContract
{
    public function render($data = null): Renderable
    {
        return view('myfield.template', ['field' => $this, 'data' => $data]);
    }

    public function store($value, $oldValue)
    {
        // transform $value before it lands on the model
        return $value;
    }
}
```

## Read-only fields (v2+)

Use `->readOnly()` to mark a field as form-display-only. The field still
renders in the form (showing the current value), but submitted input is
ignored — useful when the underlying attribute is managed by a domain
service (e.g. a status that only changes through a state machine):

```php
$this->addField(Select::class, 'Stage', 'stage')
    ->setOptions(LeadStage::asOptions())
    ->readOnly();
```

Different from `->skip()`, which is for fields that need after-save side
effects (file uploads, etc.). Read-only fields run no callback at all on
save.

## Conditional visibility (v2+)

Use `->showWhen(Closure $predicate)` to hide a field unless a condition
is met. The predicate receives the model (or `null` on create forms) and
must return bool:

```php
$this->addField(Select::class, 'Lost reason', 'lost_reason')
    ->setOptions(LostReason::asOptions())
    ->showWhen(fn ($m) => $m && $m->stage === Stage::Lost);
```

Hiding a field has no effect on saving — combine with `->readOnly()` if
you also want to reject writes when the field is hidden:

```php
$this->addField(...)->showWhen(...)->readOnly();
```

## Skip on save (vs read-only)

`->skip()` marks fields that should be processed *after* the model is
saved (file uploads using the saved model's id, for example):

```php
$this->addField(File::class, 'Avatar', 'avatar_path')
    ->skip()
    ->saveAs(function ($value, $model) {
        if ($value) {
            $path = $value->storeAs('avatars', $model->id . '.jpg');
            $model->update(['avatar_path' => $path]);
        }
    });
```

- `skip()` — runs after save, with the persisted model available
- `readOnly()` — never runs on save at all
