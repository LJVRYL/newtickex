define(['knockout', 'ko.mapping', 'domain', 'email', 'spampattern'], function(ko, mapping, Domain, Email, SpamPattern) {
    var antispamVM = function() {
        'use strict';
        var self = this;

        mediator.installTo(this);
        ko.mapping = mapping;
        this.inprocess = ko.observable(1);
        this.tabToggle = ko.observable(1);

        this.emails = ko.observableArray([]);
        this.data = ko.observableArray([]);
        this.emailselected = ko.observable(new Email());
        this.temp = ko.observable(new SpamPattern());

        this.sortDirection = ko.observable('asc');
        this.sortData = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.pattern == right.pattern ? 0 : (left.pattern < right.pattern ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.pattern == right.pattern ? 0 : (left.pattern > right.pattern ? -1 : 1);
                });
            }
        };
        this.sortDirectionType = ko.observable('asc');
        this.sortDataByType = function() {
            var self = this;
            if (self.sortDirectionType() === 'des') {
                self.sortDirectionType('asc');
                self.data.sort(function(left, right) {
                    return left.type() == right.type() ? 0 : (left.type() < right.type() ? -1 : 1);
                });
            } else {
                self.sortDirectionType('des');
                self.data.sort(function(left, right) {
                    return left.type() == right.type() ? 0 : (left.type() > right.type() ? -1 : 1);
                });
            }
        };

        this.subscribe('emailListUpdated', function(emails) {
            'use strict';
            
            var baseEmail = new Email();
            $.each(emails, function(i, email) {
                if (! email.antiSpamConfig) {
                    //workaround para cuando no se termino de crear el mail ya que vuelve como null
                    email.antiSpamConfig = baseEmail.antiSpamConfig;
                }
            });

            var mapping = {
                create: function(options) {
                    return new Email(options.data);
                },
                key: function(item) {
                    return ko.utils.unwrapObservable(item.id);
                }
            };

            ko.mapping.fromJS(emails, mapping, self.emails);
            FerozoHosting.profileVM().user().updateEmails(emails);
            self.antispamconf();
            self.inprocess(0);
        });

        this.antispamconf = function() {
            'use strict';
            self = this;
            if (FerozoHosting.emailaccountsVM()) {
                var oMailSelect = FerozoHosting.emailaccountsVM().emailAccountSelect();
                oMailSelect && oMailSelect.id && $.each(FerozoHosting.antispamVM().emails(), function(i, oMail) {
                    if (oMail.id() === oMailSelect.id()) {
                        FerozoHosting.antispamVM().emailselected(oMail);
                        FerozoHosting.antispamVM().nextStep();

                        FerozoHosting.emailaccountsVM().emailAccountSelect(undefined); //fix testear
                        return;
                    }
                });
            }
        };

        this.changeScore = function() {
            'use strict';
            var isWin = FerozoHosting.profileVM().user().Server.OpSystem() === 'Windows';

            var theData = { "params": {
                "idEmail": self.emailselected().id(),
                "score": isWin ? self.emailselected().antiSpamConfig().scoreToMove() : self.emailselected().antiSpamConfig().score(),
                "scoretomove": self.emailselected().antiSpamConfig().scoreToMove(),
                "scoretodelete": self.emailselected().antiSpamConfig().scoreToDelete(),
                "deletespam": self.emailselected().antiSpamConfig().deletespam(),
                "subjecttag": self.emailselected().antiSpamConfig().spamLabel(),
                "filterTo": self.emailselected().antiSpamConfig().subjectTag()
            }};

            self.inprocess(1);
            $.postJSON("/hosting/email/changeantispamscore", theData, function(data) {
                if (data.error && data.error.data && data.error.data.inputException) {
                    $.each(data.error.data.inputException, function() {
                        this.field = this.field === 'subject' ? 'spamLabel' : this.field;
                        this.field = this.field === 'subjecttag' ? 'spamLabel' : this.field;
                        $('input[data-bind^="value: antiSpamConfig().'+this.field+'"]').next('.help-block.error').html(this.errorDesc);
                    });
                } else if (data.result) {
                    $.each(data.result, function() {});
                    self.init();
                }
            }).always(function() {
                self.inprocess(0);
            });
        };

        this.changeMoveOrDelete = function(entity, event) {
            'use strict';
            var value = $(event.target).val();
            self.emailselected().antiSpamConfig().deletespam(value);
            //REFACT//self.changeScore();
        };

        this.changeSubject = function(entity, event) {
            'use strict';
           //REFACT//self.changeScore();
        };

        this.init = function() {
            'use strict';
            mediator.publish('refreshEmailList');
            self.antispamconf();
            if (self.emailselected && self.emailselected().id()) {
                self.list();
            }
            if (! self._initted) {
                //mediator.publish('refreshEmailList');
                mediator.publish('refreshDomainList');
            }


            var intervalId;
            intervalId = window.setInterval(function() {
                self.initRangeInputs();
                //window.clearInterval(intervalId);
            }, 1000);
            $(window).resize(function() {
                self.initRangeInputs();
            });
        };
    };

    antispamVM.prototype.initRangeInputs = function() {
        $('.fz-rangeslider').each(function() {
            var range = $(this).find('input[type="range"]');
            var peaks = $(this).find('.peaks').html('');
            var pieces = parseInt(range.attr('max')) - parseInt(range.attr('min'));
            var margin = range.width() / pieces;
            var ul = $('<ul>');
            peaks.append(ul);
            ul.html('');
            var marginCounter = 0;
            $.each(FerozoHosting.range(range.attr('min') ,range.attr('max')), function() {
                var li = $('<li>').css({"left": marginCounter});
                marginCounter += (margin - 0.3);
                li.appendTo(ul);
            });
            ul.css({"z-index": 1});
        });
        return true;
    };

    antispamVM.prototype.saveFilter = function() {
        var self = this;
        if (self.emailselected().antiSpamConfig().enabled()) {
            self.changeScore();
        } else {
            self.toggleStatus();
        }
    };

    antispamVM.prototype.toggleStatus = function() {
        var self = this;
        var theData = { "params": {
            "idEmail": this.emailselected().id(),
            "status": self.emailselected().antiSpamConfig().enabled()
        }};

        self.inprocess(1);
        $.postJSON("/hosting/email/changeantispamstatus", theData, function(data) {
            if (data.result) {
                $.each(data.result, function() {
                });
            }
            self.init();
        }).always(function() {
            self.inprocess(0);
        });
    };

    antispamVM.prototype.list = function(success) {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idEmail": this.emailselected().id()
        }};

        self.inprocess(1);
        $.postJSON("/hosting/email/listantispambothlist", theData, function(data) {
            self.data([]);
            if (data.result) {
                $.each(data.result, function() {
                    var spamPattern = new SpamPattern(this);
                    spamPattern.email(self.emailselected());
                    self.data.push(spamPattern);
                });
            }
        }).always(function() {
            self.inprocess(0);
        });;
    };

    antispamVM.prototype.nextStep = function() {
        'use strict';
        this.list();
    };

    antispamVM.prototype.openModal = function() {
        'use strict';
        //var spamPattern = new SpamPattern(this);
        var spamPattern = new SpamPattern();
        spamPattern.email(this.emailselected());
        this.temp(spamPattern);
        $('#modal-create').modal('show');
    };

    return antispamVM;
});