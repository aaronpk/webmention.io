require File.join(File.expand_path(File.dirname(__FILE__)), 'environment.rb')

while true do
  NotificationQueue.process_notifications
  sleep 10
end
