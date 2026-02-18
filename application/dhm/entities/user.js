define(['knockout', 'ko.mapping'], function(ko, mapping) {
    var User = function(data) {

        this.idReseller = ko.observable();
        this.idDhm = ko.observable();
        this.UserName = ko.observable();
        this.UserPrefix = ko.observable();
        this.Password = ko.observable();
        this.NewPassword = ko.observable();
        this.Contact = ko.observable();
        this.Hostings = ko.observable();
        this.Server = {
            OpSystem: ko.observable(),
            IpWan: ko.observable()
        };
        this.Language = {
            Name: ko.observable(),
            Id:  ko.observable(),
            AvailableLanguages: ko.observableArray([])
        };
        this.IsQuotaActive = ko.observable();
        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    User.prototype.getProfileVM = function() {
        return window.FerozoDhm && FerozoDhm.profileVM();
    };

    User.prototype.save = function() {
        var self = this;
        var theData = { "params": {
            "username": self.UserName(),
            "contactEmail": self.Contact(),
            "idLanguage": self.Language.Id()
        }};

        self.NewPassword() && (theData.params['newPassword'] = self.NewPassword());

        self.getProfileVM().inprocess(1);
        $.postJSON('/dhm/account/settings/edit', theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException, function(obj) {
                    obj.field === 'contactEmail' && (obj.field = 'Contact');
                    obj.field === 'newPassword' && (obj.field = 'NewPassword');
                    obj.field === 'username' && (obj.field = 'UserName');
                    obj.field === 'emailClient' && (obj.field = 'EmailClient');
                }).apply();
                return;
            }

            if (self.Language.Id() !== self.getProfileVM().user().Language.Id()) {
                window.location.reload();
                return;
            }
            self.getProfileVM().init().success(function() {
                $('#modal-edit').modal('hide');
            });
        }).always(function() {
            self.getProfileVM().inprocess(0);
        });
    };

    return User;
});