define(['knockout', 'features', 'featuresitems', 'sort'], function(ko, Features, FeaturesItems, Sort) {
    var featuresVM = function() {
        'use strict';

        this.inprocess = ko.observable(0);
        this.data = ko.observableArray([]);
        this.temp = ko.observable(new Features());
        this.featuresList = ko.observableArray([]);


        this.sortByDescription = new Sort(this.data, 'name');
    };

    featuresVM.prototype.init = function() {
        this.list();
        this.listAllFeatures();
    };

    featuresVM.prototype.list = function() {
        var self = this;
        var theData = { "params": {

        }};

        self.inprocess(1);
        $.postJSON("/dhm/serverconfig/server/packlist", theData, function(data) {
            self.data([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.data.push(new Features(obj));
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    featuresVM.prototype.listAllFeatures = function() {
        var self = this;
        var theData = { "params": {}};

        $.postJSON("/dhm/serverconfig/server/featureslist", theData, function(data) {
            self.featuresList([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    self.featuresList.push(new FeaturesItems(obj));
                });
            }
        });
    }

    featuresVM.prototype.openModal = function() {
        //self.temp(new Features());
        var clean = new Features()  
        ko.utils.arrayForEach(this.featuresList(), function(obj) {
            var value = obj.id().toString();
            clean.featuresEnabled().push(value);
        });
        this.temp(ko.mapping.fromJS(ko.toJS(clean)));
        $('#modal-create').modal('show');
    };

    featuresVM.prototype.openModalEdit = function(entity, event) {
        var cloned = ko.mapping.fromJS(ko.toJS(entity));
        this.temp(cloned);
        $('#modal-create').modal('show');
    };

    this.divsplit = function(value){
        if(value % 2 == 0){
            return true;
        }else{
            return false;
        }
    };

    
    return featuresVM;
});