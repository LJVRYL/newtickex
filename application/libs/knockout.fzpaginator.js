/**
 * @param {type} ko
 * @returns {fzPaginator|Function|_L6.Anonym$2}
 */

define(['knockout'], function (ko) {

    return fzPaginator = (function () {
        var self = this;

        this.paginationGroups = ko.observableArray([10, 25, 50, 100]);
        this.data = ko.observableArray([]);

        this.pageNumber = ko.observable(0);
        this.nbPerPage = ko.observable(10);
        this.totalPages = function() {
            var div = Math.floor(this.data().length / this.nbPerPage());
            div += this.data().length % this.nbPerPage() > 0 ? 1 : 0;
            return div - 1;
        };

        this.totalPagesArr = function() {
            var val = this.totalPages() >= 0 ? this.totalPages() : 0;
            return Array.apply(null, Array(++val)).map(function (_, i) {return i;});
        };

        this.paginated = ko.computed(function() {
            var first = self.pageNumber() * self.nbPerPage();
            return self.data.slice(first, first + self.nbPerPage());
        });

        this.hasPrevious = function() {
            return this.pageNumber() !== 0;
        };

        this.hasNext = function() {
            return this.pageNumber() !== this.totalPages();
        };

        this.next = function() {
            if (this.pageNumber() < this.totalPages()) {
                this.pageNumber(this.pageNumber() + 1);
            }
        };

        this.previous = function() {
            if (this.pageNumber() !== 0) {
                this.pageNumber(this.pageNumber() - 1);
            }
        };

        this.goPage = function(pageNum) {
            self.pageNumber(pageNum);
        };

        this.setNumPerPage = function(number) {
            self.pageNumber(0);
            self.nbPerPage(number);
        };

        return {
            installTo: function (obj, data) {
                self.data(data);
                obj.previous = self.previous;
                obj.next = self.next;
                obj.hasNext = self.hasNext;
                obj.hasPrevious = self.hasPrevious;
                obj.paginated = self.paginated;
                obj.totalPagesArr = self.totalPagesArr;
                obj.totalPages = self.totalPages;
                obj.nbPerPage = self.nbPerPage;
                obj.pageNumber = self.pageNumber;
                obj.setNumPerPage = self.setNumPerPage;
                obj.goPage = self.goPage;
                obj.paginationGroups = self.paginationGroups;
            }
        };
    }());
});