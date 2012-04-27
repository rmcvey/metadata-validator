$.fn.mv_form = function(jobj)
{
	var json = eval(jobj);
	var errors = [];
	$(this).submit(function(){
		var is_good = true;
		var elems = $(this).find("input[type!='submit']");
		var elem_size = elems.length;
		for(i = 0; i < elem_size; i++)
		{
			var obj = json[i];
			var validators = obj[1];
			var elem = elems.eq(i);
			if(validators !== undefined && validators !== null)
			{					
				var regexp	   = validators.pattern;
				if(regexp !== undefined && regexp !== null)
				{
					var pattern = new RegExp(validators.pattern);
					if(pattern.test(elem.val()))
					{
						errors.push(elem.prev('label').html() + " is not in a valid format");
					}
				}
				var minlength = validators.minlength;
				if(minlength !== null)
				{
					if(elem.val().length < minlength)
					{
						errors.push(elem.prev('label').html() + " is not long enough: minimum "+minlength);
					}
				}
				var maxlength = validators.maxlength;
				if(maxlength !== null)
				{
					if(elem.val().length > maxlength)
					{
						errors.push(elem.prev('label').html() + " is too long: maxlength "+maxlength);
					}
				}
				var required = validators.required;
				if(required)
				{
					if(elem.val() == "" || elem.val() == null)
					{
						errors.push(elem.prev('label').html() + " is a required field");
					}
				}
			}
			
		}
		if(errors.length > 0)
		{
			var html_errors = "";
			for(i = 0; i < errors.length; i++)
			{
				html_errors += "<li>"+errors[i]+"</li>";
			}
			$('#error_list').html("").html(html_errors).parent().show();
			is_good = false;
		}
		return is_good;
	});
}