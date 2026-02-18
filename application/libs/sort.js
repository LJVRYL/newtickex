define(['knockout'], function(ko) {

    var Sort = function(data, field, isNumeric) {
        var self = this;

        this.isNumeric = isNumeric;
        this.sortDirection = ko.observable('asc');

        this.sortData = function() {

            var parse = function(val) {
                val = ko.utils.peekObservable(val);
                return self.isNumeric ? parseInt(val) : val;
            };

            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                data.sort(function(left, right) {
                    var leftVal = parse(left[field]);
                    var rightVal = parse(right[field]);
                    return leftVal === rightVal ? 0 : (leftVal < rightVal ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                data.sort(function(left, right) {
                    var leftVal = parse(left[field]);
                    var rightVal = parse(right[field]);
                    return leftVal === rightVal ? 0 : (leftVal > rightVal ? -1 : 1);
                });
            }
        };
    };

    return Sort;
});