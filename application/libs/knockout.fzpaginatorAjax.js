/**
 * @param {type} ko
 * @returns {fzPaginatorAjax|Function|_L6.Anonym$2}
 */

define(['knockout'], function (ko) {

    var fzPaginatorAjax = function (callbackOnChange) {
        var self = this;

        this.__callbackOnChange = callbackOnChange;
        this.paginationRequesting = ko.observable(false);
        this.paginationGroups = ko.observableArray([50, 100]);
        this.pageNumber = ko.observable(1);
        this.nbPerPage = ko.observable(this.paginationGroups()[0]);
        this.totalItems = ko.observable(0);
        this.hasNext = ko.observable(true);

        this.search = function() {
            self.pageNumber(1);
            typeof callbackOnChange === 'function' && callbackOnChange();
        };
        this.clearSearch = function(query) {
            if (query.length < 1 && self.query().length < 1) {
                self.search();
            }
        };
        this.query = ko.observable('');

        this.increase = function() {
            if (self.pageNumber() < 1) {
                self.pageNumber(1);
            }
            self.pageNumber(self.pageNumber() + 1);
        };

        this.decrease = function() {
            if (self.pageNumber() > 1) {
                self.pageNumber(self.pageNumber() - 1);
            }
        };

        this.next = function() {
            if (self.hasNext()) {
                self.increase();
                self.__callbackOnChange();
            }
        };

        this.hasPrevious = function() {
            return self.pageNumber() > 1;
        };

        this.previous = function() {
            if (self.hasPrevious()) {
                self.decrease();
                self.__callbackOnChange();
            }
        };

        this.goPage = function(pageNum) {
            self.pageNumber(pageNum);
            self.__callbackOnChange();
        };

        this.setNumPerPage = function(number) {
            self.pageNumber(1);
            self.nbPerPage(number);
            self.__callbackOnChange();
        };

        this.paginationRequestParams = function() {
            return {
                "page": self.pageNumber(),
                "offset": self.nbPerPage(),
                "orderBy": "",
                "orderType": ""
            };
        };

        this.ajaxViewModelListing = function(viewModel, entity, listUrl, extraParams, pushCallback) {
            var itemCount = 0;
            var theData = {"params": {
                "pagination": self.paginationRequestParams()
            }};

            ko.utils.extend(theData.params, extraParams || {});
            viewModel.inprocess(viewModel.inprocess()+1);
            self.paginationRequesting(true);
            return $.postJSON(listUrl, theData, function(data) {
                viewModel.data([]);
                if (data.result && data.result[0]) {
                    $.each(data.result, function(i, e) {
                        itemCount++;
                        if (i >= self.nbPerPage()) {
                            return;
                        }
                        typeof pushCallback === 'function' ? pushCallback(this) : viewModel.data.push(new entity(this));
                    });
                } else {
                    self.decrease();
                }
                self.paginationRequesting(false);
                if (! self.totalItems()) {
                    self.totalItems(itemCount);
                }
                self.hasNext(self.nbPerPage() <= itemCount);
            }).always(function() {
                 viewModel.inprocess(viewModel.inprocess()-1);
            });
        };

    };

    return fzPaginatorAjax;
});