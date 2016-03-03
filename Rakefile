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

  # Generate the JSON response that XRay sends for the test data
  task :generate_stubs do
    Dir.glob(File.join File.expand_path(File.dirname(__FILE__)), 'test/data/*/*').each {|f|
      if File.file? f
        host = /data\/([^\/]+)/.match(f)[1]
        path = /data\/[^\/]+(\/.+)/.match(f)[1]
        url = "http://#{host}#{path}"
        if host != "xray.test"
          puts url
          Dir.mkdir "test/data/xray.test/#{host}" unless File.exists? "test/data/xray.test/#{host}"
          result = {:data => XRay.parse(url, nil, IO.read(f))}
          IO.write "test/data/xray.test/#{host}#{path}", result.to_json
        end
      end
    }

  end
end
