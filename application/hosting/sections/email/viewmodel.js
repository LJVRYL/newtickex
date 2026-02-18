define(['knockout', 'email'], function(ko, Email) {

    /* [VM] EmailVM
     ++++++++++++++++++++++++++++++++++++++++++++++++++++
     */

    var EmailVM = function() {
        'use strict';


        // Entities presented
        this.data = ko.observableArray([]);
        this.domains = ko.observableArray([]);

        //Entity edit mode
        this.edit = ko.observableArray([]);

        // New entity data
        this.newEmailAccountText = ko.observable('');
        this.newEmailDomainId = ko.observable('');
        this.newEmailAccountPassw = ko.observable('');
        this.newEmailAccountQuota = ko.observable('');



        mediator.installTo(this);
        /* <=[ Subscriptions ]=> */
        this.subscribe('emailListUpdated', function(emailList) {
            'use strict';
            var self = this;
            self.data.destroyAll();
            self.data.removeAll();
            $.each(emailList, function() {
                self.data.push(this);
            });
        });

        this.subscribe('emailToEdit', function(email) {
            'use strict';
            var self = this;
            self.edit.removeAll();

            self.edit.push(email);
            $('#edit').modal('show');
        });

        this.subscribe('domainListUpdated', function(domainList) {
            'use strict';
            var self = this;
            self.domains.destroyAll();
            self.domains.removeAll();
            $.each(domainList, function() {
                self.domains.push(this);
            });
        });

        this.subscribe('emailDeleted', function() {
            'use strict';
            var self = this;
            mediator.publish('refreshEmailList');
        });
        /* <=[ /Subscriptions ]=> */


    };

    EmailVM.prototype.savenew = function() {
        'use strict';
        var self = this;

        $.post('index/addemailaccount/format/json/iddomain/' + self.newEmailDomainId() + '/password/' + self.newEmailAccountPassw() + '/usernameprefix/' + self.newEmailAccountText() + '/quota/' + self.newEmailAccountQuota(), 
            function() {
                mediator.publish('refreshEmailList');
            }
     );
    };

    EmailVM.prototype.init = function() {
        'use strict';
        var self = this;

        

       // mediator.publish('refreshEmailList');
       // mediator.publish('refreshDomainList');

    };


    EmailVM.prototype.sort = function() {
        'use strict';
        var self = this;
        self.domains.sort();
    };


    return EmailVM;
//-------- / DomainVM

});