def init(env=ENV['RACK_ENV']); end
require File.join('.', 'environment.rb')
require 'rake/testtask'

Rake::TestTask.new do |t|
  t.pattern = ENV['TEST_PATTERN'] || "test/**/*_spec.rb"
end

task :default => :test

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

namespace :test do
  def pingback_client
    XMLRPC::Client.new "localhost", "/test/xmlrpc", 9019
  end

  task :sample1 do
    source_uri = "http://techslides.com/tumblr-api-example-using-oauth-and-php/?id=" + rand(100).to_s
    target_uri = "http://oauth.net/code/"
    c = pingback_client
    c.call('pingback.ping', source_uri, target_uri)
  end
end
