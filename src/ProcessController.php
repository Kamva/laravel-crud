<?php

namespace Kamva\Crud;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\ViewErrorBag;
use Kamva\Crud\Exceptions\KamvaCrudException;


class ProcessController extends Controller
{
    public function observe(Request $request)
    {
        try {
            $c      = Crypt::decryptString($request->input('c'));
            $value  = $request->input('v');

        }catch (\Exception $e){
            throw new KamvaCrudException("Invalid payload");
        }

        $c              = explode("|", $c);
        $observe        = $c[0];
        $field          = $c[1];
        $controller     = $c[2] ?? null;
        $id             = $c[3] ?? null;

        if(empty($controller)){
            throw new KamvaCrudException("Invalid c");
        }

        $controller = app($controller);

        if(!$controller instanceof CRUDController){
            throw new KamvaCrudException("invalid crud controller implementation");
        }

        $controller->init();


        $field = collect($controller->getForm()->getFields())->first(function ($formField) use($field){
            return $formField->getName() == $field;
        });

        if(empty($field)){
            throw new KamvaCrudException("field not found");
        }

        $field->field()->setFieldData($id ? $controller->checkModel($id) : null);

        $observe = collect($field->field()->getObservers())->first(function ($observer) use($observe){
           return $observer['field'] == $observe;
        });

        if(empty($observe)){
            throw new KamvaCrudException("observe not found");
        }

        $observe = $observe['callback'];

        $observe = $observe($value, $field->field());

        view()->share('errors', new ViewErrorBag());

        return $observe ? $field->render($id ? $controller->checkModel($id) : null) : null;
    }
}
