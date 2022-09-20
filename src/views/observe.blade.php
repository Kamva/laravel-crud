<script>
    $(document).ready(function (){
        $("[name={{$observe['field']}}]").trigger("change");
    });

    $("[name={{$observe['field']}}]").on("change",function (){
        $(".loading").show();

        $.post("{{route('kamva-crud.process')}}", {c: "{{$c}}",v:$(this).val()})
            .done(function (res){
                $(".loading").hide();
                $("k-crud[id='{{$field->getName()}}']").html(res);
                $("k-crud[id='{{$field->getName()}}'] .select2").select2();
                $("k-crud[id='{{$field->getName()}}'] .select2.tag").select2({tags: true});
                var ele=$("k-crud[id='{{$field->getName()}}'] .select2.sortable").parent().find("ul.select2-selection__rendered");
                ele.sortable({
                    containment: 'parent',
                    update: function() {orderSortedValues();}
                });
            })
            .fail(function (res){
                $(".loading").hide();
                alert(res.responseJSON.message || "Internal Error")
            });
    });
</script>
