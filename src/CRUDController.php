<?php

namespace Kamva\Crud;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Kamva\Crud\Actions\Internal\BaseAction;
use Kamva\Crud\Containers\ActionContainer;
use Kamva\Crud\Containers\ColumnContainer;
use Kamva\Crud\Containers\FieldContainer;
use Kamva\Crud\Containers\FilterContainer;
use Kamva\Crud\Containers\ImportProfileContainer;
use Kamva\Crud\Exceptions\FieldValidationException;
use Kamva\Crud\Exceptions\KamvaCrudException;
use Kamva\Crud\Fields\Internal\FieldContract;
use Closure;
use Maatwebsite\Excel\Facades\Excel;

class CRUDController extends Controller
{
    private $title          = '';
    private $cols           = [];
    private $apiEntities    = [];
    private $exportCols     = [];
    private $importProfiles = [];
    private $filters        = [];
    private $actions        = [];
    private $topActions     = [];
    private $preferences    = [];
    private $routeParameters = [];
    private $model;
    private $query;
    private $form;

    public function __construct(Form $form)
    {
        $this->form = $form;

        $this->middleware(function ($request, $next) {
            $this->init();
            return $next($request);
        });
    }

    public function setRouteParameters($parameters)
    {
        $this->routeParameters = $parameters;
    }

    public function setSingleType($singleType = true)
    {
        $this->setPreference('singleType', $singleType);
    }

    public function useFieldsAsApiEntities()
    {
        $this->setPreference('fields_as_api_entities', true);
    }

    public function setOrderBy($col, $order = 'desc')
    {
        $this->setPreference('orderByCol', $col);
        $this->setPreference('orderByOrder', $order);
    }

    public function getMethodRoute($name)
    {
        return Route::getRoutes()->getByAction(get_class($this) . '@' . $name);
    }

    public function options()
    {
        $res = [];
        foreach ($this->form->getFields() as $field) {
            if (!empty($field->field()->getOptions())) {
                $res[$field->getName()] = $field->field()->getOptions();
            }
        }

        return $res;
    }

    // Helpers

    private function tryToGetCreateRoute()
    {
        $createRoute    = null;

        if (!empty($this->getMethodRoute('create'))) {
            $createRoute    = route($this->getMethodRoute('create')->getName(), $this->routeParameters);
        }

        return $createRoute;
    }

    private function getApiSingleRecord($row)
    {
        $data                       = [];
        $data['id']                 = $row->id;

        foreach ($this->apiEntities as $col) {
            $data[$col->getName()]   = $col->getValue($row, true);
        }

        return $data;
    }

    private function getApiDataCollection($rows)
    {
        $response   = [];
        foreach ($rows->items() as $row) {
            $response[] = $this->getApiSingleRecord($row);
        }

        return collect($response);
    }

    private function getExportSingleRecord($row)
    {
        $data   = [];
        $cols   = empty($this->exportCols) ? $this->cols : $this->exportCols;

        foreach ($cols as $col) {
            $data[$col->getName()]   = $col->getValue($row, true);
        }

        return $data;
    }

    private function getExportDataCollection($rows)
    {
        $response   = [];
        foreach ($rows->items() as $row) {
            $response[] = $this->getExportSingleRecord($row);
        }

        return collect($response);
    }

    private function createApiResponseFromData($rows)
    {
        return [
            'current_page'      => $rows->currentPage(),
            'data'              => $rows->items(),
            'from'              => $rows->firstItem(),
            'last_page'         => $rows->lastPage(),
            'per_page'          => $rows->perPage(),
            'to'                => $rows->lastItem(),
            'total'             => $rows->total(),
        ];
    }

    private function handleApiResponse($rows)
    {
        $rows   = $rows->paginate(intval(env("CRUD_PAGINATE_SIZE")));
        $rows->setCollection($this->getApiDataCollection($rows));

        return KamvaCrud::apiResponse($this->createApiResponseFromData($rows));
    }

    private function exportData($rows)
    {
        $rows   = $rows->paginate(100000);
        $rows->setCollection($this->getExportDataCollection($rows));

        $data       = [];
        $headers    = [];

        /** @var ColumnContainer $col */
        foreach ($this->exportCols ?? $this->cols as $col) {
            $headers[] = $col->getName();
        }
        $data[] = $headers;

        foreach ($rows->items() as $item) {
            $data[] = array_values($item);
        }

        return Excel::download(new CRUDExport($data), "export" . "_" . $this->title .'_' . jdate()->format("Y_m_d"). '.xlsx');
    }

    public function getActionFieldForRow($row, $avoidGroup = false)
    {
        $colValue   = '';
        $actions = collect($this->actions)->filter(fn ($action) => $action->hasAccess($row));

        $firstActions = $avoidGroup ? $actions : $actions->take(3);
        $firstActions->each(function ($action) use ($row, &$colValue) {
            $colValue .= '<form data-toggle="tooltip" data-placement="top"  class="action-selector '. $action->getOption('class') . ($action->getOption('ask') ? 'ask' : '') .'" title="'. $action->getCaption() .'" style="margin: 0 5px;display: inline-block" method="'. ($action->isMethod('get') ? 'get' : 'post') .'" action="'.$action->url($row).'">';
            $colValue .= $action->isMethod('get') ? '' : method_field($action->getMethod());
            $colValue .= $action->isMethod('get') ? '' : csrf_field();
            $colValue .= $action->getRender($row);
            $colValue .= '</form>';
        });

        $extraActions = $avoidGroup ? collect([]) : $actions->skip(3);
        if ($extraActions->isEmpty()) {
            return $colValue;
        }

        $colValue .= '<div class="btn-group"><a data-toggle="dropdown"><i class="feather icon-more-vertical"></i></a><ul class="dropdown-menu" role="menu">';

        $extraActions->each(function ($action) use ($row, &$colValue) {
            $colValue .= '<li >';
            $colValue .= '<form class="dropdown-item action-selector '. $action->getOption('class') . ($action->getOption('ask') ? 'ask' : '') .'" method="'. ($action->isMethod('get') ? 'get' : 'post') .'" action="'.$action->url($row).'">';
            $colValue .= $action->isMethod('get') ? '' : method_field($action->getMethod());
            $colValue .= $action->isMethod('get') ? '' : csrf_field();
            $colValue .= $action->getRender($row) .'<span style="margin-right: 1rem">'.$action->getCaption().'</span>';
            $colValue .= '</form>';
            $colValue .='</li>';
        });

        $colValue .= '</ul></div>';

        return $colValue;
    }

    private function getJsonLoaderRecords($start, $rows)
    {
        $out            = [];
        $i              = $start + 1;

        foreach ($rows as $row) {
            $value      = [];

            $value[]    = $i++;

            foreach ($this->cols as $col) {
                $value[] = $col->getValue($row);
            }

            $value[]    = $this->getActionFieldForRow($row, $rows->count() < 5);
            $out[]      = $value;
        }

        return $out;
    }

    private function getRowsForJsonLoader($rows, $start, $length)
    {
        return $rows->skip($start)->take($length);
    }

    private function searchInJsonLoader($rows, $text)
    {
        if (empty($text)) {
            return $rows;
        }

        return $rows->where(function ($q) use ($text) {
            foreach ($this->cols as $item) {
                $q->orWhere($item->guessColNameInDB(), "like", "%" . $text . "%");
            }
        });
    }

    private function orderRowsInJsonLoader($rows, $by, $dir)
    {
        if(!empty($this->getPreference('orderByCol'))){
            $rows->orderBy($this->getPreference('orderByCol'), $this->getPreference('orderByOrder'));

            return $rows;
        }

        if (empty($by) || count($this->cols) < $by) {
            return $rows->orderBy("created_at", "desc");
        }

        $by = $this->cols[$by - 1];
        $by = $by->guessColNameInDB();

        if (!empty($by)) {
            return $rows->orderBy($by, $dir);
        }

        return $rows;
    }

    private function handleJsonLoaderResponse(Request $request, $rows)
    {
        $start          = (int) $request->input('start');
        $length         = (int) $request->input('length');
        $search         = $request->input('search')['value'] ?? null;

        $order          = $request->input('order')[0]['column'] ?? null;
        $orderDir       = $request->input('order')[0]['dir'] ?? 'desc';

        $initialRows    = clone $rows;

        $rows           = $this->searchInJsonLoader($rows, $search);

        $rows           = $this->orderRowsInJsonLoader($rows, $order, $orderDir);

        $filteredRows   = clone $rows;

        $rows           = $this->getRowsForJsonLoader($rows, $start, $length);


        $rows           = $rows->get();
        return [
            "data"              => $this->getJsonLoaderRecords($start, $rows),
            "recordsTotal"      => count($initialRows->get()),
            "recordsFiltered"   => count($filteredRows->get()),
            "draw"              => $request->input('draw'),
        ];
    }

    public function checkModel($id, $assign = true)
    {
        $data       = $id ? $this->getModel($assign)->find($id) : ($this->getPreference('showLatestInAPI') ? $this->getModel()->latest()->first() : $this->getModel()->first());

        if (empty($data)) {
            if ($this->getPreference('singleType')) {
                $data = $this->getInstance();
                $data->save();
            } else {
                abort(404);
            }
        }

        KamvaCrud::set('model', $data->id ?? null);

        return $data;
    }

    protected function handleSuccessResponse($message)
    {
        return KamvaCrud::isApi() ? KamvaCrud::apiResponse(['message' => $message]) : redirect()->route($this->getMethodRoute('index')->getName())->with("success", $message);
    }

    private function handleFailedResponse($message, $code = 400)
    {
        return KamvaCrud::isApi() ? abort($code, $message) : redirect()->back()->with("danger", $message);
    }

    protected function handleException(\Exception $exception, $code = 400)
    {
        if ($exception instanceof FieldValidationException) {
            throw ValidationException::withMessages([
                $exception->getFieldName() => $exception->getMessage()
            ]);
        }

        return $this->handleFailedResponse($exception->getMessage(), $code);
    }

    private function applyFilters(Request $request, Builder &$rows)
    {
        foreach ($this->filters as $filter) {
            /** @var FilterContainer $filter */

            if (!empty($request->get($filter->getInputName()))) {
                $filter->apply($request, $rows);
            }
        }
    }
    //CRUD

    public function index(Request $request)
    {
        if ($this->getPreference('singleType')) {
            return $this->edit(null);
        }

        $title          = $this->title  . ' ' . '::  نمایش لیست';
        $cols           = $this->cols;
        /** @var Builder $rows */
        $rows           = $this->getModel();
        $createRoute    = $this->tryToGetCreateRoute();
        $storeRoute     = (!empty($this->getMethodRoute('store')) && !empty($this->getMethodRoute('store')->getName())) ? route($this->getMethodRoute('store')->getName(), $this->routeParameters) : null;
        $importProfiles = collect($this->importProfiles)->map(function ($item, $i) {
            return [
                'id'    => $item->getId(),
                'title' => $item->getName(),
            ];
        })->pluck("title", "id")->toArray();


        $this->applyFilters($request, $rows);

            
        if ($request->has('export')) {
            $rows->orderBy("created_at", "asc");
            return $this->exportData($rows);
        }

        if (KamvaCrud::isApi()) {
        
            if (empty($rows->getQuery()->orders)) {
                $rows->orderBy("created_at", "desc");
            }

            return $this->handleApiResponse($rows);
        } else {
            if ($request->wantsJson()) {
                return $this->handleJsonLoaderResponse($request, $rows);
            }

            $filters    = collect($this->filters)->filter(fn ($f) => $f->hasField())->values()->all();
            $topActions = $this->getTopActions();
            return view('kamva-crud::list', compact('title', 'cols', 'createRoute', 'importProfiles', 'storeRoute', 'filters', 'topActions'));
        }
    }

    public function create()
    {
        return $this->showForm();
    }

    public function show($id = null)
    {
        $data   = $this->checkModel($id);

        if (KamvaCrud::isApi()) {
            $data = $this->getApiSingleRecord($data);
            return KamvaCrud::apiResponse([
                'data'  => $data,
            ]);
        } else {
            return $this->showForm($data, true);
        }
    }

    public function edit($id)
    {
        $data   = $this->checkModel($id);

        return $this->showForm($data);
    }

    public function downloadSample($profileId)
    {
        $profile = $this->importProfiles[$profileId] ?? null;

        if (empty($profile)) {
            return $this->handleFailedResponse("پروفایل انتخاب شده نامعتبر است");
        }

        $file = $profile->getSampleFile();
        if (empty($file)) {
            $out = [];
            foreach ($profile->getFields() as $field) {
                $out[0][] = $field->getName();
            }

            return Excel::download(new CRUDExport($out), 'sample_'.$profile->getId().'.xlsx');
        }

        return response()->download($file);
    }

    public function ImportFromProfile($profileId, UploadedFile $file)
    {
        $profile = $this->importProfiles[$profileId] ?? null;

        if (empty($profile)) {
            return $this->handleFailedResponse("پروفایل انتخاب شده نامعتبر است");
        }

        $profile->import($this->getInstance(), $file);

        return $this->handleSuccessResponse('اطلاعات با موفقیت درج شد');
    }

    public function store(Request $request)
    {
        $model       = $this->getInstance();

        if ($request->has('profile_id')) {
            if ($request->get('type') == 'sample') {
                return $this->downloadSample($request->get('profile_id'));
            }

            if (!$request->hasFile('import')) {
                return redirect()->back()->with("danger", "فایل انتخاب نشده است");
            }

            return $this->ImportFromProfile($request->get('profile_id'), $request->file('import'));
        }

        $this->form->validate($request);

        try {
            $this->form->saveToModel($request, $model, true);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }



        return $this->handleSuccessResponse("ذخیره با موفقیت انجام شد");
    }

    public function update($id, Request $request)
    {
        $model  = $this->checkModel($id, false);

        $this->form->validate($request, $model);

        try {
            $this->form->saveToModel($request, $model, true);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }

        $model->save();

        return $this->handleSuccessResponse("به روز رسانی با موفقیت انجام شد");
    }

    public function destroy($id)
    {
        $model       = $this->checkModel($id, false);

        try {
            /** @var FieldContainer $field */
            foreach ($this->form->getFields() as $field) {
                $field->field()->destroy($model);
            }
        } catch (\Exception $e) {
            return $this->handleException($e);
        }

        $model->delete();
        return $this->handleSuccessResponse("حذف با موفقیت انجام شد");
    }

    // Setters
    public function setTitle($title)
    {
        $this->title = $title;
    }

    public function setModel($model)
    {
        $this->model = $model;
    }

    public function setQuery(Closure $query)
    {
        $this->query = $query;
    }

    private function assignQuery(&$model)
    {
        $query = $this->query;

        if (empty($query)) {
            return;
        }

        if ($query instanceof Closure) {
            $model = $model->where(function ($q) use ($query) {
                $query($q);
            });
        } else {
            throw new KamvaCrudException("invalid query");
        }
    }

    public function setPreference($key, $value): void
    {
        $this->preferences[$key] = $value;
    }

    // Getters
    public function getModel($assignQuery = true)
    {
        $model = app($this->model)->newQuery();

        if ($assignQuery) {
            $this->assignQuery($model);
        }

        return $model;
    }

    public function getInstance()
    {
        return app($this->model);
    }

    public function getPreference($key = null)
    {
        return $key ? ($this->preferences[$key] ?? null) : $this->preferences;
    }

    public function getForm()
    {
        return $this->form;
    }

    private function showForm($data = null, $readOnly = false)
    {
        if (KamvaCrud::isApi()) {
            abort(400);
        }

        $route      = $this->getMethodRoute(($data ? ($readOnly ? 'show' : 'update') : 'store'));

        if (empty($route)) {
            abort(400);
        }

        $action     = route($route->getName(), $data ? array_merge($this->routeParameters, [$data->id ]) : $this->routeParameters);
        $method     = $route->methods()[0];
        $title      = $this->title . ' ' . ($data ? ($readOnly ? '::  نمایش رکورد' : '::  ویرایش رکورد') : '::  ثبت رکورد جدید');
        $form       = $this->form;

        $form->setAction($action, $method);
        $form->setRenderAsReadOnly($readOnly);
        $form->setFieldData($data);

        return view('kamva-crud::create', compact('form', 'data', 'title'));
    }


    // Adders

    /**
     * @param $title
     * @param $value
     * @return ColumnContainer
     */
    public function addColumn($title, $value)
    {
        $this->cols[] = new ColumnContainer($title, $value);
        return end($this->cols);
    }

    /**
     * @param $title
     * @param $value
     * @return ColumnContainer
     */
    public function addApiEntity($title, $value = null)
    {
        $this->apiEntities[] = new ColumnContainer($title, $value ?? $title);
        return end($this->apiEntities);
    }


    /**
     * @param $id
     * @param $title
     * @param $fields
     * @return ImportProfileContainer
     */
    public function addImportProfile($id, $title, $fields)
    {
        $this->importProfiles[$id] = new ImportProfileContainer($id, $title, $fields);
        return end($this->importProfiles);
    }

    /**
     * @param $title
     * @param $value
     * @return ColumnContainer
     */
    public function addExportEntity($title, $value = null)
    {
        $this->exportCols[] = new ColumnContainer($title, $value ?? $title);
        return end($this->exportCols);
    }

    /**
     * @param $input
     * @param \Closure $callback
     * @param FieldContract $field
     * @return FilterContainer
     */
    public function addFilter($input, \Closure $callback, ?FieldContract $field = null)
    {
        $this->filters[] = new FilterContainer($input, $callback, $field);
        return end($this->filters);
    }

    /**
     * Register a filter that has no UI form field. The callback is applied
     * to the query whenever the named input is present in the request
     * (typically via query string), but no filter widget is rendered in the
     * filter form. Useful for "deep link" filters (e.g. dashboard widgets
     * linking to `?overdue=1`) and for scoping that's controlled by something
     * other than the user-visible filter form (e.g. a "show only mine" toggle
     * rendered elsewhere in the UI).
     *
     * @return FilterContainer
     */
    public function addHiddenFilter(string $input, \Closure $callback)
    {
        return $this->addFilter($input, $callback);
    }

    /**
     * Register a multi-column LIKE search filter. The callback used by
     * {@see addFilter()} is generated for you: it lowercases the input and
     * OR-joins case-insensitive `LIKE %term%` against each column. `%` and
     * `_` are escaped so user input can't act as wildcards.
     *
     * Use this for the typical "search by name OR email OR phone" pattern
     * rather than rolling raw whereRaw clauses each time.
     *
     * @param array  $columns Column names to search.
     * @param string $input   Request input name (default: 'q').
     * @param FieldContract|null $field Optional field for rendering a search
     *        box. Pass null for hidden behavior (still filters when ?q=… is
     *        present).
     * @return FilterContainer
     */
    public function addSearchField(array $columns, string $input = 'q', ?FieldContract $field = null)
    {
        $callback = function ($request, $rows) use ($columns, $input) {
            $term = (string) $request->get($input);
            if ($term === '') return;

            $escaped = addcslashes(mb_strtolower($term), '%_\\');
            $like    = '%' . $escaped . '%';

            $rows->where(function ($q) use ($columns, $like) {
                foreach ($columns as $col) {
                    // LOWER(col) is broadly portable; database collation
                    // determines whether the underlying LIKE itself is
                    // case-sensitive.
                    $q->orWhereRaw("LOWER({$col}) LIKE ?", [$like]);
                }
            });
        };

        return $this->addFilter($input, $callback, $field);
    }

    public function addFieldFilter($input, \Closure $callback, $fieldName)
    {
        $field = collect($this->form->getFields())->first(function ($field) use ($fieldName) {
            return $field->getName() == $fieldName;
        });
        if (empty($field)) {
            throw new KamvaCrudException("invalid Field Name [{$fieldName}]");
        }

        return $this->addFilter($input, $callback, $field);
    }

    /**
     * @param $type
     * @param $route
     * @param null $acm
     * @return ActionContainer
     */
    public function addAction($type, $route, $acm = null)
    {
        /** @var BaseAction $type */
        if (!$type instanceof BaseAction) {
            $type = app($type);
        }

        $type = $type->getAction();

        $type->setRoute($route);

        if (!empty($acm)) {
            $type->setAccessControlMethod($acm);
        }

        $this->actions[] = $type;

        return end($this->actions);
    }

    /**
     * Register a page-level action — a button that appears in the page
     * header rather than per row. Use for things like "Switch to Kanban
     * view", "Export all", "Create" (when you want a non-default location),
     * or links to related screens.
     *
     * Unlike {@see addAction()} (which is row-scoped and is rendered next to
     * each table row), top actions are global and rendered once at the top
     * of the list/index page.
     *
     * Views consume these via {@see getTopActions()}; the list view must
     * loop over that array and render each button — the package's stock
     * `views/list.blade.php` is a stub, so app-published views are expected
     * to opt in.
     *
     * Each top action is returned as an associative array:
     *
     *     ['caption' => 'Switch to Kanban', 'route' => 'crud.lead.index',
     *      'params' => ['view' => 'kanban'], 'icon' => 'feather icon-columns',
     *      'class' => 'btn-sm btn-secondary']
     *
     * @param string $caption  Button label.
     * @param string $route    Named route to link to.
     * @param array  $options  {
     *     @var array  $params  Route params (default: []).
     *     @var string $icon    Icon class (default: '').
     *     @var string $class   CSS class for the <a> (default: 'btn-sm btn-secondary').
     *     @var \Closure|null $accessControlMethod Optional gate; receives no
     *          args and must return bool. False hides the action.
     * }
     * @return array The stored action entry.
     */
    public function addTopAction(string $caption, string $route, array $options = []): array
    {
        $entry = [
            'caption' => $caption,
            'route'   => $route,
            'params'  => $options['params']  ?? [],
            'icon'    => $options['icon']    ?? '',
            'class'   => $options['class']   ?? 'btn-sm btn-secondary',
            'accessControlMethod' => $options['accessControlMethod'] ?? null,
        ];
        $this->topActions[] = $entry;
        return $entry;
    }

    /**
     * Get the registered top actions, filtered through their access control
     * methods. Views call this to render the page-header button bar.
     *
     * @return array<int, array{caption:string, route:string, params:array, icon:string, class:string, url:string}>
     */
    public function getTopActions(): array
    {
        $out = [];
        foreach ($this->topActions as $a) {
            if ($a['accessControlMethod'] instanceof \Closure && ! ($a['accessControlMethod'])()) {
                continue;
            }
            $a['url'] = route($a['route'], $a['params']);
            unset($a['accessControlMethod']);
            $out[] = $a;
        }
        return $out;
    }

    /**
     * @param $type
     * @param $caption
     * @param $name
     * @param null $value
     * @param array $options
     * @return FieldContract
     */
    public function addField(...$params)
    {
        $field = $this->form->addField(...$params);

        if ($this->getPreference('fields_as_api_entities')) {
            $this->addApiEntity($field->getName(), $field->getInitialValue());
        }

        return $field;
    }

    public function addRequiredField(...$params)
    {
        return $this->addField(...$params)->setValidation(['required']);
    }

    public function init()
    {
        if (method_exists($this, 'setup')) {
            $this->setup();
        } else {
            throw new KamvaCrudException("Setup method not found");
        }

        KamvaCrud::set('class', $this);
    }
}
