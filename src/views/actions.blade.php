{{--
    Row actions cell for the list view, rendered by CRUDController::getActionFieldForRow().
    Variables: $row (the record), $inlineActions and $groupedActions (collections of
    ActionContainer; the grouped ones go into the dropdown). Values are echoed unescaped,
    as before. Whitespace between elements shows up as gaps between the inline-block
    buttons, so keep every element of an action on one line.
--}}
@foreach ($inlineActions as $action)
<form data-toggle="tooltip" data-placement="top"  class="action-selector {!! $action->getOption('class') . ($action->getOption('ask') ? 'ask' : '') !!}" title="{!! $action->getCaption() !!}" style="margin: 0 5px;display: inline-block" method="{!! $action->isMethod('get') ? 'get' : 'post' !!}" action="{!! $action->url($row) !!}">@unless ($action->isMethod('get')){!! method_field($action->getMethod()) !!}{!! csrf_field() !!}@endunless{!! $action->getRender($row) !!}</form>@endforeach
@if ($groupedActions->isNotEmpty())
<div class="btn-group"><a data-toggle="dropdown"><i class="feather icon-more-vertical"></i></a><ul class="dropdown-menu" role="menu">@foreach ($groupedActions as $action)
<li ><form class="dropdown-item action-selector {!! $action->getOption('class') . ($action->getOption('ask') ? 'ask' : '') !!}" method="{!! $action->isMethod('get') ? 'get' : 'post' !!}" action="{!! $action->url($row) !!}">@unless ($action->isMethod('get')){!! method_field($action->getMethod()) !!}{!! csrf_field() !!}@endunless{!! $action->getRender($row) !!}<span style="margin-right: 1rem">{!! $action->getCaption() !!}</span></form></li>@endforeach
</ul></div>@endif
