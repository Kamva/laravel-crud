# CLAUDE.md — AI Assistant Context for kamva/laravel-crud

This file gives AI coding assistants the context they need to work effectively
in this repository.

---

## What This Package Is

`kamva/laravel-crud` is a **Laravel package** (Composer library, not a standalone
app) that provides a reusable CRUD scaffolding system. Consuming applications
extend `CRUDController`, implement a `setup()` method, and the package takes
care of listing, creating, editing, deleting, exporting, and importing records.

- **Package namespace**: `Kamva\Crud`
- **Composer name**: `kamva/laravel-crud`
- **Source root**: `src/`
- **Auto-registered provider**: `Kamva\Crud\KamvaCRUDServiceProvider`
- **License**: MIT
- **Author**: Mehdi Meshkatian

---

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP >= 7.4 |
| Framework | Laravel >= 6.0 |
| Excel I/O | `maatwebsite/excel` ^3.1 |
| Front-end (views) | Blade stubs (consumers implement them) |
| Date formatting | Jalali/Persian calendar (`jdate()` helper assumed in host app) |

There is no `package.json`, no `artisan`, no migrations, no tests directory.
This is a pure PHP Composer package.

---

## Repository Layout

```
src/
├── KamvaCRUDServiceProvider.php   # Registers singleton, routes, views
├── KamvaCrud.php                  # Laravel Facade (wraps Service)
├── Service.php                    # Concrete service behind the facade
├── CRUDController.php             # Base controller – consumers extend this
├── ProcessController.php          # Handles field-observer AJAX endpoint
├── Form.php                       # Manages form field collection
├── CRUDExport.php                 # Excel export (FromArray)
├── CRUDImport.php                 # Excel import (chunked, starts row 2)
├── helpers.php                    # Global makeField() helper
├── routes.php                     # Single route: POST /kc-process/observe
├── Actions/
│   └── Internal/BaseAction.php    # Base class for custom row actions
├── Containers/
│   ├── ActionContainer.php        # Row action config (route, ACL, render)
│   ├── ColumnContainer.php        # List column config + value resolver
│   ├── FieldContainer.php         # Form field config + lazy instantiation
│   ├── FilterContainer.php        # Query filter with associated field
│   └── ImportProfileContainer.php # Excel import profile definition
├── Exceptions/
│   ├── KamvaCrudException.php     # General package exception
│   └── FieldValidationException.php # Thrown inside field store() calls
├── Extensions/
│   ├── Extension.php              # Wraps a Closure + type tag
│   └── ExtensionManager.php       # Registry; current type: "store"
├── Fields/
│   └── Internal/
│       ├── FieldContract.php      # Interface every field type must satisfy
│       ├── BaseField.php          # Abstract implementation of FieldContract
│       └── FieldSource.php        # Resolves model/builder → key-value options
└── views/
    ├── list.blade.php             # Stub: variables $title,$cols,$createRoute…
    ├── create.blade.php           # Stub: variables $title,$form,$data
    ├── observe.blade.php          # jQuery AJAX for field observers (complete)
    └── fields/
        └── read_only.blade.php    # Stub: variables $field,$data
```

---

## Core Concepts

### 1. CRUDController — the entry point for consumers

Consumers extend `CRUDController` and implement `setup()`:

```php
class ProductController extends CRUDController
{
    public function setup(): void
    {
        $this->setTitle('Products');
        $this->setModel(Product::class);

        $this->addColumn('Name',  'name');
        $this->addColumn('Price', fn($row) => '$' . $row->price);

        $this->addField(TextType::class, 'Name',  'name')
             ->setValidation(['required', 'string']);
        $this->addField(NumberType::class, 'Price', 'price');
    }
}
```

`init()` calls `setup()` inside a middleware so it runs after routing. If
`setup()` doesn't exist, a `KamvaCrudException` is thrown.

### 2. Dual Web / API mode

`Service::isApi()` returns `true` when:
- The request path starts with `api`
- The route is `kamva-crud.process`

In API mode `index()` returns paginated JSON. In web mode it returns a Blade
view (DataTables JSON when `wantsJson()`).

### 3. Field system

All field types implement `FieldContract` (and usually extend `BaseField`).
The package ships the interface and base class; **concrete field types are
provided by the consuming application**.

Key `BaseField` features:
- `setValue($value|Closure)` — static default or computed
- `setSource(Model|Closure|Builder, $key, $value)` — populates options from DB
- `saveAs(Closure)` — transforms value before saving
- `observe($otherFieldName, Closure)` — triggers AJAX re-render of this field
  when `$otherFieldName` changes
- `setValidation([...])` — appends Laravel validation rules
- `setMultiple()` — marks field as multiple-value (array)
- `skip()` — skips normal Eloquent assignment; runs callback after model save

### 4. ColumnContainer value resolution

When a column value is a string, `ColumnContainer::getValue()` resolves it:

| Value format | Resolved as |
|---|---|
| `'name'` | `$row->name` |
| `'status.badge'` | Calls `KamvaCrud::callColumnType('badge', $row, 'status', …)` then falls back to built-in methods |
| `'field_name.field'` | Reads value through the form field (applying options map) |
| `Closure` | `$closure($row, $raw)` |

### 5. Extensions

Extensions intercept the **store** pipeline:

```php
KamvaCrud::addExtension(ExtensionManager::STORE_TYPE, function ($context) {
    // $context is the value returned by the field's store()
    return $context;
});
```

### 6. Actions

Actions are row-level buttons rendered in the list view. First three render
as buttons; extras collapse into a dropdown.

```php
$this->addAction(EditAction::class, 'products.edit', fn($row) => true);
```

`EditAction` extends `BaseAction` (set `$method`, `$caption`, `$render`,
`$parameters`).

### 7. Import / Export

- **Export**: `GET ?export=1` on `index()` → downloads `.xlsx` via `CRUDExport`
  (up to 100,000 rows). File name includes a jalali date.
- **Import**: `POST store` with `profile_id` → runs `CRUDImport` (chunked 50
  rows, skips header row).
- **Import profiles** define per-column mappings and can supply a sample file.

### 8. Single-type mode

`setSingleType(true)` makes the index redirect to the edit form for the first
(or latest) record — useful for settings pages.

### 9. Views

Views live in `src/views/` under the `kamva-crud::` namespace. Three of the
four are stubs (`// Implement Me !`). Consumers publish them and implement the
UI:

```bash
php artisan vendor:publish --provider="Kamva\\Crud\\KamvaCRUDServiceProvider"
```

The observe view (`kamva-crud::observe`) is fully implemented and uses jQuery +
Select2 to handle dynamic field updates.

---

## Environment Variables

| Variable | Used in | Purpose |
|---|---|---|
| `CRUD_PAGINATE_SIZE` | `CRUDController::handleApiResponse()` | Records per page for API pagination |

---

## Registered Routes

| Method | URI | Name | Handler |
|---|---|---|---|
| POST | `/kc-process/observe` | `kamva-crud.process` | `ProcessController@observe` |

---

## Key Facade Methods (KamvaCrud::*)

```php
KamvaCrud::addExtension($type, Closure $callable)   // Register pipeline extension
KamvaCrud::addColumnType(string $name, Closure $cb) // Register custom column type
KamvaCrud::apiResponse($data, $code = 200)          // Build JSON response
KamvaCrud::set($key, $value)                        // Set request-scoped data
KamvaCrud::get($key)                                // Get request-scoped data
KamvaCrud::setDefaultACLMethod(Closure $callable)   // Global ACL for actions
KamvaCrud::isApi()                                  // Is this an API request?
```

---

## Common Patterns When Contributing

### Adding a new field type
1. Create a class that extends `BaseField` (or directly implements `FieldContract`)
2. Implement `render($data): Renderable` and `renderAsReadOnly($data): Renderable`
3. Optionally override `store($value, $oldValue)`, `rules()`, `destroy($model)`

### Adding a new action type
1. Extend `BaseAction`
2. Set `$method`, `$caption`, `$render`, `$parameters`, `$options` in the class body
3. Register with `$this->addAction(MyAction::class, 'route.name')`

### Adding a column type extension
```php
KamvaCrud::addColumnType('badge', function ($data, $col, $params, $raw) {
    return '<span class="badge">' . $data->$col . '</span>';
});
// Then use in addColumn: $this->addColumn('Status', 'status.badge')
```

---

## Architectural Notes

- **No migrations / no database tables** — this is a UI/controller layer only.
- **No tests** — the package ships without a test suite.
- **Persian text** — user-facing messages (success/error flash, UI strings) are
  in Farsi (Persian). Do not translate them unless asked.
- **`jdate()`** — the export filename uses a jalali date helper; this must be
  provided by the host application.
- **`<k-crud>` custom element** — `Form::wrapWithDiv()` wraps each field in a
  `<k-crud id="fieldName">` element. The observer JS targets these.
- **Encrypted observer payloads** — `ProcessController` decrypts a payload
  containing `observedField|thisField|ControllerClass|modelId`. Never pass raw
  class names from client to server without this encryption layer.
