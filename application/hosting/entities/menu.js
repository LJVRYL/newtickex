define(['knockout'], function(ko) {
    /* ------------ TAKS -----------------*/
    var Menu = function(menuItem) {
        'use strict';
        this.account = ko.observable(account || '');
        this.commandtemplate = ko.observable(commandtemplate || '');
        this.em = ko.observable(em || '');
        this.id = ko.observable(id || '');
        this.operations = ko.observable(operations || '');
        this.params = ko.observable(params || '');
        this.retries = ko.observable(retries || '');
        this.scheduleddate = ko.observable(scheduleddate || '');
        this.status = ko.observable(status || '');
    };

    return Menu;
});