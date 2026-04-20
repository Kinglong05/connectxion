class RealtimeAPI {
      constructor(options = {}) {
                this.apiUrl = options.apiUrl || 'realtime_api.php';
                this.userId = options.userId || null;

          this.listeners = {};
                this.mode = 'polling';
                this.pusher = null;

          this.pusherKey = window.PUSHER_APP_KEY || '';
                this.pusherCluster = window.PUSHER_APP_CLUSTER || '';

          this.init();
      }

    init() {
              if (typeof Pusher !== 'undefined' && this.pusherKey) {
                            this.connectPusher();
              } else {
                            console.warn("Pusher library or Key not found. Falling back to polling.");
                            this.fallbackPolling();
              }
    }

    connectPusher() {
              console.log("Connecting to Pusher WebSocket...");
              this.pusher = new Pusher(this.pusherKey, {
                            cluster: this.pusherCluster,
                            forceTLS: true,
            authEndpoint: window.BASE_URL + '/api/pusher_auth.php'
              });

          this.mode = 'socket';

          if (this.userId) {
                        const userChannel = this.pusher.subscribe(`private-user-${this.userId}`);

                  userChannel.bind('msg-received', (data) => {
                                    this.emit('new_messages', [data]);
                  });

                  userChannel.bind('user-typing', (data) => {
                                    this.emit('typing', [data.user_id]);
                  });

                  userChannel.bind('friend-req', (data) => {
                                    this.emit('friend_requests', [data]);
                  });
          }

          const publicChannel = this.pusher.subscribe('public-updates');
              publicChannel.bind('ping', (data) => {
                            console.log("Ping received from Serverless Function:", data);
              });

          this.pusher.connection.bind('connected', () => {
                        console.log("Pusher WebSockets connected successfully.");
                        this.emit('connected', { mode: 'socket' });
          });

          this.pusher.connection.bind('disconnected', () => {
                        console.warn("Pusher disconnected.");
                        this.fallbackPolling();
          });
    }

    syncStatus() {
              fetch(`${this.apiUrl}?action=get_online`)
                  .then(r => r.json())
                  .then(data => {
                                    if (data.status === 'success') {
                                                          this.emit('online_friends', data.online_friends);
                                    }
                  }).catch(() => {});

          fetch(`${this.apiUrl}?action=get_unread`)
                  .then(r => r.json())
                  .then(data => {
                                    if (data.status === 'success') {
                                                          this.emit('unread_counts', data.unread_counts);
                                    }
                  }).catch(() => {});
    }

    fallbackPolling() {
              this.mode = 'polling';
              setInterval(() => this.syncStatus(), 10000);
    }

    on(event, callback) {
              if (!this.listeners[event]) {
                            this.listeners[event] = [];
              }
              this.listeners[event].push(callback);
    }

    off(event, callback) {
              if (!this.listeners[event]) return;
              this.listeners[event] = this.listeners[event].filter(cb => cb !== callback);
    }

    emit(event, data) {
              if (!this.listeners[event]) return;
              this.listeners[event].forEach(callback => {
                            try {
                                              callback(data);
                            } catch (err) {
                                              console.error(`Error in event listener for ${event}:`, err);
                            }
              });
    }

    ping() {
              fetch(`${this.apiUrl}?action=ping`).catch(console.error);
    }

    sendTypingIndicator(receiverId, isTyping) {
              const formData = new FormData();
              formData.append('action', 'typing');
              formData.append('receiver_id', receiverId);
              formData.append('typing', isTyping ? 1 : 0);

   
