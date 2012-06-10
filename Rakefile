def init(env=ENV['RACK_ENV']); end
require File.join('.', 'environment.rb')

namespace :db do
  task :bootstrap do
    init
    DataMapper.auto_migrate!
    Account.create :username => 'pingback'
  end
  
  task :migrate do
    init
    DataMapper.auto_upgrade!
  end
end

