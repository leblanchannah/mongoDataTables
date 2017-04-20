
// adapted from https://www.youtube.com/watch?v=-FNyIz37j3I

//displayForm sends input from form generated to changeField 
function displayForm( cell, columnName ) { 
	var table_type = "part";
	if ($("#t1").hasClass("active")) {
		table_type = "full";
	}  
	var column = columnName, 
		id = cell.closest('tr').attr('id'),
		cellWidth = cell.css('width'),
		prevContent = cell.text(), // populate with previous content when clicked on
		form = '<form id="edit" action="javascript: this.preventDefault"><input type="text" size="4" maxlength="140" name="newValue" value="'+
   				prevContent+'"/><input type="hidden" name="id" value="'+id+'"/>'+
   				'<input type="hidden" name="column" value="'+column+'"/></form>';
	

   	// gets input from value 
	cell.html(form).find('input[type=text]')
		.focus()
		.css('width',cellWidth);


	cell.on('click',function(){return false}); //prevent form from recreating
	// when someone clicks do nothing the second time, no jquery reaction triggered

	// when done editing the form
	cell.on('keydown', function(e) {
		// if pressed key is enter, submit
		if (e.keyCode == 13) {
			changeField(cell, prevContent, table_type);
			
		} else if (e.keyCode == 27) {
			// if escape, puts back original value, renables editing by removing 2nd click handler 
			cell.text(prevContent);
			cell.off('click');
		}
	});
}// end displayForm 

//changeField updates the datatable via ajax get request to update.php which sends
// an update query to the table
function changeField(cell, prevContent, table_type) {
	cell.off('keydown');
	var url2 = '',
	input = cell.find('form').serialize()+"&table="+table_type; 
		

// sending through url, passing directly using getJSON
    var request = $.ajax({
        type: 'GET',
        url: url2,
        data: input,
        dataType: 'json'
    });
    request.done( function(data) {
		if (data.success) {
		
			cell.html(data.value);
		} else {
			alert("There was a problem updating the data. Please try again.");
			cell.html(prevContent);
	}
       
    });
    request.fail( function (jqXhr, textStatus, errorThrown) {
    	// if ajax request fails the previous content will be filled back in 
    	alert("There was a problem updating the data. Please try again.");
		cell.html(prevContent);
    });
    cell.off('click');
} // end changeField

