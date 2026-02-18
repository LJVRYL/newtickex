define(['knockout', 'panelalert', 'ko.mapping', 'fzPaginatorAjax'], function(ko, PanelAlert, mapping, fzPaginatorAjax) {

    return function panelalertsVM() {
        'use strict';

        var self = this;

        this.inprocess = ko.observable(0);
        this.data = ko.observableArray([]);
        this.temp = ko.observable(new PanelAlert());
        
        this.panelAlertUsers = ko.observableArray([]);
        this.query = ko.observable('');
        this.fillUser = ko.observable('');
        this.fillState = ko.observable('');
      
        ko.mapping = mapping;

        this.statuses = [
            {label: 'NEW', actionLabel: 'NEW', value: 'NEW'},
            {label: 'ASSIGNED', actionLabel: 'ASSIGNED', value: 'ASSIGNED'},
            {label: 'IN PROGRESS', actionLabel: 'IN PROGRESS', value: 'IN PROGRESS'},
            {label: 'FIXED', actionLabel: 'FIXED', value: 'FIXED'},
            {label: 'CANCELED', actionLabel: 'CANCELED', value: 'CANCELED'}
        ];
        
        this.loadPanelAlertUsers = function() {
            $.postJSON("/dashboard/panelalert/users/list", function(data) {
                if (data.result) {
                    //self.panelAlertUsers.push([{id:0,name:'Seleccionar'}]);
                    //self.panelAlertUsers.push(data.result);
                    self.panelAlertUsers(data.result);
                }
            });
        };
        
        mediator.installTo(this);
        this.pagination = new fzPaginatorAjax(function() {
            self.listPaginated();
        });

        this.sortDirection = ko.observable('des');
        this.sortData = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.auditCreation() == right.auditCreation() ? 0 : (left.auditCreation() < right.auditCreation() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.auditCreation() == right.auditCreation() ? 0 : (left.auditCreation() > right.auditCreation() ? -1 : 1);
                });
            }
        };

//        this.opennew = function(dom, idmodal, event) {
//            'use strict';
//            var self = this;
//            self.temp(new PanelAlert());
//            $('#' + idmodal).modal('show');
//        };



        this.subscribe('refreshPanelAlertListAjax', function() {
            this.listPaginated();
        });

        this.listPaginated = function() {
            self.pagination.ajaxViewModelListing(this, PanelAlert, "/dashboard/panelalert/list");
        };

        this.init = function() {
            'use strict';
            mediator.publish('refreshPanelAlertListAjax');
            self.loadPanelAlertUsers();
        };
        this.getUsername = function (data){
            var exp=new RegExp("username=([0-9a-z]*)");
            var res = exp.exec(data.content());    
            if(res instanceof Array) {
                return res[1];
            }
            
            var exp=new RegExp("user=([0-9a-z]*)");
            var res = exp.exec(data.content());    
              if(res instanceof Array) {
                return res[1];
            }
            
            var exp=new RegExp("\"username\":\"([0-9a-z]*)\"");
            var res = exp.exec(data.content());    
              if(res instanceof Array) {
                return res[1];
            }
            return "";
        }
        this.deletePanelAlert = function (entity, event){
            entity.remove();
            FerozoDashboard.panelalertsVM().data.remove(entity);
            
        };
        this.isSearch =  function (data){
            var self = this;
            if (self.fillState() != "" &&  data.status() != self.fillState() ) return false;
            if (self.fillUser() != "" && data.panelAlertUserName() != self.fillUser() ) return false;
            
            
            if (this.query() == "") {
                return true;
            } else {
                var username = this.getUsername(data);
                var e = new RegExp(this.query());
                if (username.match(e)) {
                    return true;
                } else {
                    return false;
                }
            }
            return false;
        }
        
        this.editAlert = function(entity, event) {
            var cloned = ko.mapping.fromJS(ko.toJS(entity));
            FerozoDashboard.panelalertsVM().temp(cloned);
            $("#alertinfo").modal('hide');
            $("#edit").modal('show');
        };
        
//        this.showInfo = function(entity, event) {
//            var cloned = ko.mapping.fromJS(ko.toJS(entity));
//            FerozoDashboard.panelalertsVM().temp(cloned);
//            $("#alertinfo-modal").modal('show');
//        };        
        
        this.showInfo = function(entity, event) {
            var cloned = ko.mapping.fromJS(ko.toJS(entity));
            FerozoDashboard.panelalertsVM().temp(cloned);
            $("#alertlist").hide();
            $("#alertinfo").show();
        };        
        
        this.hideInfo = function() {
            $("#alertinfo").hide();
            $("#alertlist").show();
        };        
    };
});