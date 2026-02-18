define(function() {

    var extend = function(ko) {

        ko.observable.fn.errors = function() {
            if (! ko.isObservable(this.internal)) {
                this.internal = ko.observable(arguments[0]);
                return this.internal();
            }
            if (arguments.length > 0) {
                this.internal(arguments[0]);
                return this.internal;
            }
            return this.internal();
        };

        ko.utils.setObservableErrors = function(inputException, callback) {
            var self = this;
            ko.utils.arrayForEach(inputException, function(property) {
                var obj = self;
                typeof callback === 'function' && callback(property);
                property.field.split('.').forEach(function(e, i) {
                    obj = obj[e];
                });
                ko.isObservable(obj) && obj.errors(property.errorDesc);
            });
        };

        ko.utils.clearObservableErrors = function(undefined, callback) {
            var self = this;
            ko.utils.objectForEach(self, function(property) {
                var obj = self;
                property.split('.').forEach(function(e, i) {
                    obj = obj[e];
                });
                try {
                    ko.isObservable(obj) && obj.errors(undefined);
                    typeof callback === 'function' && callback(obj);
                } catch (e) {}
            });
        };
    };

    return {
        extend: extend
    };
});