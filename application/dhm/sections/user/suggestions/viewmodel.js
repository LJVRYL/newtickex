define(['knockout'], function(ko) {
    var SuggestionsVM = function() {
        'use strict';

        this.stars = ko.observableArray([1, 2, 3, 4, 5]);
        this.inprocess = ko.observable(0);
        this.sent = ko.observable(false);
        this.message = ko.observable();
        this.starSelected = ko.observable(0);
    };
    
    SuggestionsVM.prototype.init = function() {
    };

    SuggestionsVM.prototype.openModal = function() {
        $('#modal-suggestions').modal('show');
        //$('#modal-suggestions').show();
        //$('#sugerencia').show();
        //console.log("mostrando..");
    };

    SuggestionsVM.prototype.starClick = function(item) {
        var self = this;
        self.starSelected(item);
    };

    SuggestionsVM.prototype.send = function() {
        var self = this;
        var modal = $('#modal-suggestions');
        var theData = { "params": {
            "message": self.message(),
            "rating": self.starSelected()
        }};

        self.inprocess(1);
        modal.find('.help-block.error').html('');
        $.postJSON('/dhm/account/addfeedback', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    modal.find('[name="'+ this.field +'"]').parent().find('.help-block.error').html(this.errorDesc);
                });
            } else {
                self.sent(true);
            }
        }).always(function() {
            self.inprocess(0);
        });
    };
    return SuggestionsVM;
});