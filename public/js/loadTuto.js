$(window).load(function() {    
    $("div[role=tutorial]").each(function() {

        var div = $(this);

        var position = div.position();

        div.css({
            position: 'relative'
        });
    });
    
});

var tutoData = [];
var current = 0;

var skipTutorial = function() {
	$("div[role=tutorial]").tooltip('hide');
};

var nextTutorial = function() {

	if(tutoData.length != 0) { // To avoid overflow... else we have first : d = tutoData[0] and overflow
		
        var d = tutoData[current];
        
        if(current < tutoData.length) {
            
            // Template html for tutorial and show the current tuto
            $("div[role=tutorial][id = " + d.div + "]")
                .attr("data-html", "true")
                .attr("title", d.text + "<a href=# onClick=nextTutorial() >Next</a>")
                .tooltip('show');
            
            // Hide all of other tutos
            $("div[role=tutorial][id != " + d.div + "]").tooltip('hide');
            current++;
        }
        else {
            // Hide all of the tutos
            skipTutorial();
        }
	}

};

function loadTutorial(tuto) {
    
    Tutorial(tuto, function(data) {

        var tutoDataFiltered = [];

        data.forEach(function(d) {             
            tutoData.push(d);
        });

        nextTutorial();
    });

};

/*
$("#txtSpecialization" + currentId).attr("data-toggle", "tooltip");
         $("#txtSpecialization" + currentId).attr("data-trigger", "manual");
         $("#txtSpecialization" + currentId).attr("title", "You have to enter a specialization before adding another one, or press the 'Create!' button if you don't want to have specialization.");
         $("#txtSpecialization" + currentId).tooltip("show");
         $("#txtSpecialization" + currentId).focus();

         // Hide specialization field's tooltip when the user unfocus it.
         $("#txtSpecialization" + currentId).blur(function() {
            $(this).tooltip("hide");
         });
         */