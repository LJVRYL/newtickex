define(['knockout'], function(ko) {

    var LoginVM = function() {
        'use strict';
        this.inprocess = ko.observable(0);
        this.user = ko.observable('');
        this.password = ko.observable('');
        this.errors = ko.observable();
        mediator.installTo(this);
    };

    LoginVM.prototype.init = function() {
    };

    LoginVM.prototype.login = function() {
        var self = this;

        //fix knockout issue http://stackoverflow.com/questions/7923669
        $("#form-login").find("input").change();

        var theData = { "params": {
            "user": self.user(),
            "password": self.password()
        }};

        self.inprocess(1);
        self.errors('');
        $.postJSON('/common/loginajax', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self.errors(this.errorDesc);
                });
            } else {
                self.password('');
                FerozoUtils.security.requestToken();
                FerozoDashboard.connection.needlogin(0);
                FerozoDashboard.getActiveSectionVM().init();
            };
        }).always(function() {
            self.inprocess(0);
        });
    };

    return LoginVM;
});
