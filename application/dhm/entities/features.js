define(['knockout', 'ko.mapping', 'notifications'], function(ko, mapping, Notifications) {
    var Features = function(data) {
        var self = this;

        this.regStatus = ko.observable(1);
        this.rowstatus = ko.observable();

        this.id = ko.observable();
        this.name = ko.observable();
        this.featuresEnabled = ko.observableArray([]);
        
        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    Features.prototype.getFeaturesVM = function() {
        return FerozoDhm && FerozoDhm.featuresVM();
    };

    Features.prototype.toJS = function() {
        var obj = ko.toJS(this, {ignore: ["__ko_mapping__"]});
        delete obj.__ko_mapping__;
        return obj;
    };

    Features.prototype.remove = function() {
        var self = this;
        var theData = { "params": {
            "id": self.id()
        }};

        self.regStatus(4);
        $.postJSON("/dhm/serverconfig/server/packremove", theData, function(data) {
            if (data.result) {
                Notifications.success('Se ha eliminado correctamente');
                self.getFeaturesVM().list();
            }

        }).fail(function(data) {
        }).always(function(data) {
            data.error && self.regStatus(1);
            self.getFeaturesVM().inprocess(0);
        });
    };

    Features.prototype.save = function() {
        var self = this;
        var theData = { "params": self.toJS() };

        self.getFeaturesVM().inprocess(1);
        $.postJSON("/dhm/serverconfig/server/packedit", theData, function(data) {
            if (data.result) {
                Notifications.success('Se ha creado correctamente');
                self.getFeaturesVM().list();
                $('#modal-create').modal('hide');
            }

        }).fail(function(data) {
        }).always(function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
                data.error.data.userException && Notifications.error(data.error.data.userException.value);
            }
            self.getFeaturesVM().inprocess(0);
        });
    };

    return Features;
});