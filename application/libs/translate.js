define(['knockout'], function(ko) {

    var Translate = function(element, updateTime, infinite) {
        var value = $(element).html();
        var observable = ko.observable(value);
        var updateKey;

        observable.getValue = function() {
            return ko.utils.peekObservable(observable);
        };

        updateKey = window.setTimeout(function() {
            observable($(element).html());
            infinite || window.clearTimeout(updateKey);
        }, updateTime || 300);

        return observable;
    };

    return Translate;
});