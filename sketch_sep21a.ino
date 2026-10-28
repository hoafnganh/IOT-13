#include <ESP8266WiFi.h>
#include <PubSubClient.h>
#include <DHT.h>

const char* ssid = "P702";
const char* password = "b23dcat037";

const char* mqtt_server = "192.168.1.59";
const int mqtt_port = 3777;

const char* mqtt_user = "anhdh";
const char* mqtt_password = "2507";

const char* topic_telemetry = "iot/esp01/telemetry";
const char* topic_status    = "iot/esp01/status";
const char* topic_led       = "iot/esp01/led/status";

const char* topic_led1_state = "iot/esp01/led1/state";
const char* topic_led2_state = "iot/esp01/led2/state";
const char* topic_led_result = "iot/esp01/led/result";

#define LED1 D1
#define LED2 D4
#define DHTPIN D5

DHT dht(DHTPIN,DHT11);
WiFiClient wifi;
PubSubClient client(wifi);

unsigned long t=0;

void callback(char*,byte* p,unsigned int n){
  String s;
  while(n--) s+=(char)*p++;
  s.trim();
  s.toUpperCase();

  if(s=="LED1_ON"){
    digitalWrite(LED1,HIGH);
    client.publish(topic_led1_state,"ON");
  }
  else if(s=="LED1_OFF"){
    digitalWrite(LED1,LOW);
    client.publish(topic_led1_state,"OFF");
  }
  else if(s=="LED2_ON"){
    digitalWrite(LED2,HIGH);
    client.publish(topic_led2_state,"ON");
  }
  else if(s=="LED2_OFF"){
    digitalWrite(LED2,LOW);
    client.publish(topic_led2_state,"OFF");
  }
  else if(s=="BOTH_ON"){
    digitalWrite(LED1,HIGH);
    digitalWrite(LED2,HIGH);
    client.publish(topic_led1_state,"ON");
    client.publish(topic_led2_state,"ON");
  }
  else if(s=="BOTH_OFF"){
    digitalWrite(LED1,LOW);
    digitalWrite(LED2,LOW);
    client.publish(topic_led1_state,"OFF");
    client.publish(topic_led2_state,"OFF");
  }
  else{
    client.publish(topic_led_result,"FAILED");
    return;
  }

  client.publish(topic_led_result,"SUCCESS");
}

void mqtt(){
  while(!client.connected())
    if(client.connect("ESP8266",mqtt_user,mqtt_password,
      topic_status,0,true,"offline")){
      client.publish(topic_status,"online",true);
      client.subscribe(topic_led);
    }else delay(2000);
}

void setup(){
  pinMode(LED1,OUTPUT);
  pinMode(LED2,OUTPUT);

  digitalWrite(LED1,LOW);
  digitalWrite(LED2,LOW);

  dht.begin();

  WiFi.begin(ssid,password);
  while(WiFi.status()!=WL_CONNECTED) delay(500);

  client.setServer(mqtt_server,mqtt_port);
  client.setCallback(callback);
}

void loop(){
  if(!client.connected()) mqtt();
  client.loop();

  if(millis()-t<3000) return;
  t=millis();

  float temp=dht.readTemperature();
  float hum=dht.readHumidity();
  int light=map(analogRead(A0),1023,0,0,100);

  if(isnan(temp)||isnan(hum)) return;

  String s="{\"deviceId\":\"esp01\",\"temperature\":"+
  String(temp,1)+",\"humidity\":"+String(hum,1)+
  ",\"light\":"+String(light)+
  ",\"led1\":\""+(digitalRead(LED1)?"ON":"OFF")+"\""+
  ",\"led2\":\""+(digitalRead(LED2)?"ON":"OFF")+"\"}";

  client.publish(topic_telemetry,s.c_str());
}