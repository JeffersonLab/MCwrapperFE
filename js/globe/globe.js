/**
 * dat.globe Javascript WebGL Globe Toolkit
 * https://github.com/dataarts/webgl-globe
 *
 * Copyright 2011 Data Arts Team, Google Creative Lab
 *
 * Licensed under the Apache License, Version 2.0 (the 'License');
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 */

var DAT = DAT || {};

DAT.Globe = function(container, opts) {
  opts = opts || {};
  
  var colorFn = opts.colorFn || function(x) {
    var c = new THREE.Color();
    c.setHSL( ( 0.6 - ( x * 0.5 ) ), 1.0, 0.5 );
    return c;
  };
  var imgDir ='./js/globe/' ;// opts.imgDir || '/globe/';

  var Shaders = {
    'earth' : {
      uniforms: {
        'texture': { type: 't', value: null }
      },
      vertexShader: [
        'varying vec3 vNormal;',
        'varying vec2 vUv;',
        'void main() {',
          'gl_Position = projectionMatrix * modelViewMatrix * vec4( position, 1.0 );',
          'vNormal = normalize( normalMatrix * normal );',
          'vUv = uv;',
        '}'
      ].join('\n'),
      fragmentShader: [
        'uniform sampler2D texture;',
        'varying vec3 vNormal;',
        'varying vec2 vUv;',
        'void main() {',
          'vec3 diffuse = texture2D( texture, vUv ).xyz;',
          'float intensity = 1.05 - dot( vNormal, vec3( 0.0, 0.0, 1.0 ) );',
          'vec3 atmosphere = vec3( 1.0, 1.0, 1.0 ) * pow( intensity, 3.0 );',
          'gl_FragColor = vec4( diffuse + atmosphere, 1.0 );',
        '}'
      ].join('\n')
    },
    'atmosphere' : {
      uniforms: {},
      vertexShader: [
        'varying vec3 vNormal;',
        'void main() {',
          'vNormal = normalize( normalMatrix * normal );',
          'gl_Position = projectionMatrix * modelViewMatrix * vec4( position, 1.0 );',
        '}'
      ].join('\n'),
      fragmentShader: [
        'varying vec3 vNormal;',
        'void main() {',
          'float intensity = pow( 0.8 - dot( vNormal, vec3( 0, 0, 1.0 ) ), 12.0 );',
          'gl_FragColor = vec4( 1.0, 1.0, 1.0, 1.0 ) * intensity;',
        '}'
      ].join('\n')
    }
  };

  var camera, scene, renderer, w, h;
  var mesh, atmosphere, point;
  var cameraPos0;

  var StartTime=Date.now();
  var doAniRot=true;

  var overRenderer;

  var curZoomSpeed = 0;
  var zoomSpeed = 50;

  var mouse = { x: 0, y: 0 }, mouseOnDown = { x: 0, y: 0 };
  var rotation = { x: 0, y: 0 },
      target = { x: Math.PI*3/2, y: Math.PI / 6.0 },
      targetOnDown = { x: 0, y: 0 };

  var distance = 100000, distanceTarget = 100000;
  var padding = 40;
  var PI_HALF = Math.PI / 2;

  var RADIUS = 200;
  var overlay;          // project view objects (pulses, streamers, bars)
  var tweens = [];      // fn(now) -> true when finished
  var bars = {};        // id -> mesh
  var glowTexture;

  function init() {

    container.style.color = '#fff';
    container.style.font = '13px/20px Arial, sans-serif';

    var shader, uniforms, material;
    w = container.offsetWidth || window.innerWidth;
    h = container.offsetHeight || window.innerHeight;

    camera = new THREE.PerspectiveCamera(30, w / h, 1, 10000);
    camera.position.z = distance;
    cameraPos0=0;


    scene = new THREE.Scene();
    scene.background = new THREE.Color( 0x000000 );
    var geometry = new THREE.SphereGeometry(200, 40, 30);

    shader = Shaders['earth'];
    uniforms = THREE.UniformsUtils.clone(shader.uniforms);

    //uniforms['texture'].value = THREE.ImageUtils.loadTexture(imgDir+'world.jpg');
    var d = new Date();
    var n = d.getHours();
    console.log("n: ");
    console.log(n)
    uniforms['texture'].value = THREE.ImageUtils.loadTexture(imgDir+'world_color.jpg');
    /*if(n<19)
    {
    uniforms['texture'].value = THREE.ImageUtils.loadTexture(imgDir+'day_night.png');
    }
    else
    {
      uniforms['texture'].value = THREE.ImageUtils.loadTexture(imgDir+'night_day.png');
    }*/
    material = new THREE.ShaderMaterial({

          uniforms: uniforms,
          vertexShader: shader.vertexShader,
          fragmentShader: shader.fragmentShader

        });

    mesh = new THREE.Mesh(geometry, material);
    mesh.rotation.y = Math.PI;
    scene.add(mesh);

    shader = Shaders['atmosphere'];
    uniforms = THREE.UniformsUtils.clone(shader.uniforms);

    material = new THREE.ShaderMaterial({

          uniforms: uniforms,
          vertexShader: shader.vertexShader,
          fragmentShader: shader.fragmentShader,
          side: THREE.BackSide,
          blending: THREE.AdditiveBlending,
          transparent: true

        });

    mesh = new THREE.Mesh(geometry, material);
    mesh.scale.set( 1.1, 1.1, 1.1 );
    scene.add(mesh);

    geometry = new THREE.BoxGeometry(0.75, 0.75, 1);
    geometry.applyMatrix(new THREE.Matrix4().makeTranslation(0,0,-0.5));

    point = new THREE.Mesh(geometry);

    overlay = new THREE.Group();
    scene.add(overlay);
    glowTexture = makeGlowTexture();

    renderer = new THREE.WebGLRenderer({antialias: true});
    renderer.setSize(w, h);

    renderer.domElement.style.position = 'absolute';

    container.appendChild(renderer.domElement);

    container.addEventListener('mousedown', onMouseDown, false);

    container.addEventListener('wheel', onMouseWheel, {passive: false});

    renderer.domElement.style.touchAction = 'none';
    container.addEventListener('touchstart', onTouchStart, {passive: false});
    container.addEventListener('touchmove', onTouchMove, {passive: false});
    container.addEventListener('touchend', onTouchEnd, false);
    container.addEventListener('touchcancel', onTouchEnd, false);

    document.addEventListener('keydown', onDocumentKeyDown, false);

    window.addEventListener('resize', onWindowResize, false);

    container.addEventListener('mouseover', function() {
      overRenderer = true;
    }, false);

    container.addEventListener('mouseout', function() {
      overRenderer = false;
    }, false);
  }

  function addData(data, opts) {
    var lat, lng, size, color, i, step, colorFnWrapper;

    opts.animated = opts.animated || false;
    this.is_animated = opts.animated;
    opts.format = opts.format || 'magnitude'; // other option is 'legend'
    if (opts.format === 'magnitude') {
      step = 3;
      colorFnWrapper = function(data, i) { return colorFn(data[i+2]); }
    } else if (opts.format === 'legend') {
      step = 4;
      colorFnWrapper = function(data, i) { return colorFn(data[i+3]); }
    } else {
      throw('error: format not supported: '+opts.format);
    }

    if (opts.animated) {
      if (this._baseGeometry === undefined) {
        this._baseGeometry = new THREE.Geometry();
        for (i = 0; i < data.length; i += step) {
          lat = data[i];
          lng = data[i + 1];
//        size = data[i + 2];
          color = colorFnWrapper(data,i);
          size = 0;
          addPoint(lat, lng, size, color, this._baseGeometry);
        }
      }
      if(this._morphTargetId === undefined) {
        this._morphTargetId = 0;
      } else {
        this._morphTargetId += 1;
      }
      opts.name = opts.name || 'morphTarget'+this._morphTargetId;
    }
    var subgeo = new THREE.Geometry();
    for (i = 0; i < data.length; i += step) {
      lat = data[i];
      lng = data[i + 1];
      color = colorFnWrapper(data,i);
      size = data[i + 2];
      size = size*200;
      addPoint(lat, lng, size, color, subgeo);
    }
    if (opts.animated) {
      this._baseGeometry.morphTargets.push({'name': opts.name, vertices: subgeo.vertices});
    } else {
      this._baseGeometry = subgeo;
    }

  };
  
  function createPoints() {
    if (this._baseGeometry !== undefined) {
      if (this.is_animated === false) {
        this.points = new THREE.Mesh(this._baseGeometry, new THREE.MeshBasicMaterial({
              color: 0xffffff,
              vertexColors: THREE.FaceColors,
              morphTargets: false
            }));
      } else {
        if (this._baseGeometry.morphTargets.length < 8) {
          console.log('t l',this._baseGeometry.morphTargets.length);
          var padding = 8-this._baseGeometry.morphTargets.length;
          console.log('padding', padding);
          for(var i=0; i<=padding; i++) {
            console.log('padding',i);
            this._baseGeometry.morphTargets.push({'name': 'morphPadding'+i, vertices: this._baseGeometry.vertices});
          }
        }
        this.points = new THREE.Mesh(this._baseGeometry, new THREE.MeshBasicMaterial({
              color: 0xffffff,
              vertexColors: THREE.FaceColors,
              morphTargets: true
            }));
      }
      scene.add(this.points);
    }
  }


  function addPoint(lat, lng, size, color, subgeo) {

    var phi = (90 - lat) * Math.PI / 180;
    var theta = (180 - lng) * Math.PI / 180;

    point.position.x = 200 * Math.sin(phi) * Math.cos(theta);
    point.position.y = 200 * Math.cos(phi);
    point.position.z = 200 * Math.sin(phi) * Math.sin(theta);

    point.lookAt(mesh.position);

    point.scale.z = Math.max( size, 0.1 ); // avoid non-invertible matrix
    point.updateMatrix();

    for (var i = 0; i < point.geometry.faces.length; i++) {

      point.geometry.faces[i].color = color;

    }
    if(point.matrixAutoUpdate){
      point.updateMatrix();
    }
    subgeo.merge(point.geometry, point.matrix);
  }

  function onMouseDown(event) {
    event.preventDefault();

    if (doAniRot) syncRotationFromCamera();
    doAniRot=false;
    container.addEventListener('mousemove', onMouseMove, false);
    container.addEventListener('mouseup', onMouseUp, false);
    container.addEventListener('mouseout', onMouseOut, false);

    mouseOnDown.x = - event.clientX;
    mouseOnDown.y = event.clientY;

    targetOnDown.x = target.x;
    targetOnDown.y = target.y;

    container.style.cursor = 'move';
  }

  function onMouseMove(event) {
    mouse.x = - event.clientX;
    mouse.y = event.clientY;

    var zoomDamp = distance/1000;

    target.x = targetOnDown.x + (mouse.x - mouseOnDown.x) * 0.005 * zoomDamp;
    target.y = targetOnDown.y + (mouse.y - mouseOnDown.y) * 0.005 * zoomDamp;

    target.y = target.y > PI_HALF ? PI_HALF : target.y;
    target.y = target.y < - PI_HALF ? - PI_HALF : target.y;
    cameraPos0=Math.acos(camera.position.x/distance);
    StartTime=Date.now();
  }

  function onMouseUp(event) {
    doAniRot=false;
  
    container.removeEventListener('mousemove', onMouseMove, false);
    container.removeEventListener('mouseup', onMouseUp, false);
    container.removeEventListener('mouseout', onMouseOut, false);
    container.style.cursor = 'auto';
  }

  function onMouseOut(event) {
    container.removeEventListener('mousemove', onMouseMove, false);
    container.removeEventListener('mouseup', onMouseUp, false);
    container.removeEventListener('mouseout', onMouseOut, false);
  
  }

  var pinchDist = 0;

  function touchDistance(touches) {
    var dx = touches[0].clientX - touches[1].clientX;
    var dy = touches[0].clientY - touches[1].clientY;
    return Math.sqrt(dx * dx + dy * dy);
  }

  function beginTouchDrag(touch) {
    mouseOnDown.x = - touch.clientX;
    mouseOnDown.y = touch.clientY;
    targetOnDown.x = target.x;
    targetOnDown.y = target.y;
  }

  function onTouchStart(event) {
    // leave taps on the legend/buttons overlaid on the canvas alone
    if (event.target !== renderer.domElement) return;
    event.preventDefault();
    if (doAniRot) syncRotationFromCamera();
    doAniRot = false;
    if (event.touches.length === 1) {
      beginTouchDrag(event.touches[0]);
    } else if (event.touches.length === 2) {
      pinchDist = touchDistance(event.touches);
    }
  }

  function onTouchMove(event) {
    if (event.target !== renderer.domElement) return;
    event.preventDefault();
    if (event.touches.length === 1) {
      onMouseMove(event.touches[0]);
    } else if (event.touches.length === 2) {
      var d = touchDistance(event.touches);
      zoom((d - pinchDist) * 2);
      pinchDist = d;
    }
  }

  function onTouchEnd(event) {
    // lifting one finger of a pinch: continue dragging from the remaining finger without a jump
    if (event.touches.length === 1) beginTouchDrag(event.touches[0]);
  }

  function onMouseWheel(event) {
    event.preventDefault();
    if (overRenderer) {
      // deltaMode 1 = lines (Firefox), 2 = pages
      var dy = event.deltaY * (event.deltaMode === 1 ? 33 : event.deltaMode === 2 ? 400 : 1);
      zoom(-dy * 0.3);
    }
    return false;
  }

  function onDocumentKeyDown(event) {
    switch (event.keyCode) {
      case 38:
        zoom(100);
        event.preventDefault();
        break;
      case 40:
        zoom(-100);
        event.preventDefault();
        break;
    }
  }

  function onWindowResize( event ) {
    camera.aspect = container.offsetWidth / container.offsetHeight;
    camera.updateProjectionMatrix();
    renderer.setSize( container.offsetWidth, container.offsetHeight );
  }

  function zoom(delta) {
    distanceTarget -= delta;
    distanceTarget = distanceTarget > 1000 ? 1000 : distanceTarget;
    distanceTarget = distanceTarget < 350 ? 350 : distanceTarget;
  }

  function animate() {
   // console.log(camera.position);
    requestAnimationFrame(animate);
   var omega=.0002;
   //console.log(Math.acos(camera.position.x/distance))
   if(doAniRot)
   {
    var t=Date.now();
   /* var elapsedT=t-StartTime;
    var deltaX=-1*distanceTarget*omega*Math.sin(omega*t);
    var deltaY=omega*distanceTarget*Math.cos(omega*t);*/
    camera.position.x=distance*Math.cos(omega*t+cameraPos0)
    camera.position.y=camera.position.y;
    camera.position.z=distance*Math.sin(omega*t+cameraPos0);
    camera.updateProjectionMatrix();
    camera.updateMatrixWorld();
   }
    
    render();
  }

  function render() {
    var now = Date.now();
    for (var ti = tweens.length - 1; ti >= 0; ti--) {
      if (tweens[ti](now)) tweens.splice(ti, 1);
    }

    zoom(curZoomSpeed);

    rotation.x += (target.x - rotation.x) * 0.1;
    rotation.y += (target.y - rotation.y) * 0.1;
    distance += (distanceTarget - distance) * 0.3;

    if(!doAniRot)
    {
    camera.position.x = distance * Math.sin(rotation.x) * Math.cos(rotation.y);
    camera.position.y = distance * Math.sin(rotation.y);
    camera.position.z = distance * Math.cos(rotation.x) * Math.cos(rotation.y);
    }
    camera.lookAt(mesh.position);

    renderer.render(scene, camera);
  }

  init();
  animate()
  this.animate = animate;


  this.__defineGetter__('time', function() {
    return this._time || 0;
  });

  this.__defineSetter__('time', function(t) {
    var validMorphs = [];
    var morphDict = this.points.morphTargetDictionary;
    for(var k in morphDict) {
      if(k.indexOf('morphPadding') < 0) {
        validMorphs.push(morphDict[k]);
      }
    }
    validMorphs.sort();
    var l = validMorphs.length-1;
    var scaledt = t*l+1;
    var index = Math.floor(scaledt);
    for (i=0;i<validMorphs.length;i++) {
      this.points.morphTargetInfluences[validMorphs[i]] = 0;
    }
    var lastIndex = index - 1;
    var leftover = scaledt - index;
    if (lastIndex >= 0) {
      this.points.morphTargetInfluences[lastIndex] = 1 - leftover;
    }
    this.points.morphTargetInfluences[index] = leftover;
    this._time = t;
  });

  // ---------------- project view / animation API ----------------

  function latLngToVec3(lat, lng, r) {
    var phi = (90 - lat) * Math.PI / 180;
    var theta = (180 - lng) * Math.PI / 180;
    return new THREE.Vector3(
      r * Math.sin(phi) * Math.cos(theta),
      r * Math.cos(phi),
      r * Math.sin(phi) * Math.sin(theta));
  }

  function makeGlowTexture() {
    var c = document.createElement('canvas');
    c.width = c.height = 64;
    var ctx = c.getContext('2d');
    var g = ctx.createRadialGradient(32, 32, 0, 32, 32, 32);
    g.addColorStop(0, 'rgba(255,255,255,1)');
    g.addColorStop(0.25, 'rgba(255,255,255,0.8)');
    g.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, 64, 64);
    return new THREE.CanvasTexture(c);
  }

  function makeGlow(color, size) {
    var s = new THREE.Sprite(new THREE.SpriteMaterial({
      map: glowTexture, color: color, blending: THREE.AdditiveBlending,
      transparent: true, depthWrite: false }));
    s.scale.set(size, size, 1);
    return s;
  }

  function makeRing(lat, lng, color) {
    var ring = new THREE.Mesh(
      new THREE.RingGeometry(1.6, 2.2, 40),
      new THREE.MeshBasicMaterial({ color: color, transparent: true, opacity: 1,
        side: THREE.DoubleSide, blending: THREE.AdditiveBlending, depthWrite: false }));
    ring.position.copy(latLngToVec3(lat, lng, RADIUS + 0.6));
    ring.lookAt(new THREE.Vector3(0, 0, 0));
    return ring;
  }

  function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }
  function easeOutBack(t) { var c = 1.70158; return 1 + (c + 1) * Math.pow(t - 1, 3) + c * Math.pow(t - 1, 2); }
  function easeInOut(t) { return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; }

  function disposeObject(obj) {
    obj.traverse(function(o) {
      if (o.geometry && o.geometry !== barGeometry) o.geometry.dispose();
      if (o.material) o.material.dispose();
    });
  }

  function syncRotationFromCamera() {
    var p = camera.position, len = p.length() || 1;
    rotation.y = Math.asin(Math.max(-1, Math.min(1, p.y / len)));
    rotation.x = Math.atan2(p.x, p.z);
    target.x = rotation.x;
    target.y = rotation.y;
  }

  // Turn the camera so (lat, lng) faces the viewer; stops auto-rotation.
  function focusOn(lat, lng) {
    if (doAniRot) syncRotationFromCamera();
    doAniRot = false;
    var tx = (lng - 90) * Math.PI / 180;
    while (tx - rotation.x > Math.PI) tx -= 2 * Math.PI;
    while (tx - rotation.x < -Math.PI) tx += 2 * Math.PI;
    target.x = tx;
    target.y = Math.max(-PI_HALF, Math.min(PI_HALF, lat * Math.PI / 180));
  }

  function setAutoRotate(on) {
    if (on && !doAniRot) {
      cameraPos0 = Math.atan2(camera.position.z, camera.position.x) - 0.0002 * Date.now();
    }
    doAniRot = on;
  }

  // Persistent glowing pulse (submit location).
  function pulseAt(lat, lng, color) {
    color = color || 0x66ccff;
    var core = makeGlow(color, 14);
    core.position.copy(latLngToVec3(lat, lng, RADIUS + 1));
    overlay.add(core);
    var rings = [makeRing(lat, lng, color), makeRing(lat, lng, color), makeRing(lat, lng, color)];
    for (var i = 0; i < rings.length; i++) overlay.add(rings[i]);
    var t0 = Date.now(), period = 1800;
    tweens.push(function(now) {
      var e = now - t0;
      core.scale.setScalar(12 + 4 * Math.sin(e / 250));
      for (var i = 0; i < rings.length; i++) {
        var p = ((e + i * period / rings.length) % period) / period;
        rings[i].scale.setScalar(1 + 5 * p);
        rings[i].material.opacity = 1 - p;
      }
      return false;
    });
  }

  // One-shot ring flash on landing.
  function flashAt(lat, lng, color) {
    var ring = makeRing(lat, lng, color);
    overlay.add(ring);
    var t0 = Date.now();
    tweens.push(function(now) {
      var p = Math.min(1, (now - t0) / 700);
      ring.scale.setScalar(1 + 3 * easeOutCubic(p));
      ring.material.opacity = 1 - p;
      if (p >= 1) { overlay.remove(ring); disposeObject(ring); return true; }
      return false;
    });
  }

  // Comet-like streamer from -> to ({lat, lon}); onLand() fires when the head arrives.
  function launch(from, to, delayMs, color, onLand) {
    color = new THREE.Color(color || 0x66ccff);
    var a = latLngToVec3(from.lat, from.lon, 1);
    var b = latLngToVec3(to.lat, to.lon, 1);
    var angle = a.angleTo(b);
    var start = Date.now() + (delayMs || 0);

    if (angle < 0.01) {
      // same place (e.g. submitted from the site it runs at): just land
      tweens.push(function(now) {
        if (now < start) return false;
        if (onLand) onLand();
        return true;
      });
      return { stop: function() {}, setColor: function() {}, setRate: function() {} };
    }

    var N = 80, tail = 22, base = 0.12;
    var lift = 15 + 70 * angle / Math.PI;
    var positions = new Float32Array(N * 3), colors = new Float32Array(N * 3);
    var tmp = new THREE.Vector3(), axis = new THREE.Vector3().crossVectors(a, b);
    if (axis.lengthSq() < 1e-8) axis.set(0, 1, 0).cross(a); // antipodal
    if (axis.lengthSq() < 1e-8) axis.set(1, 0, 0);
    axis.normalize();
    var pts = [];
    for (var i = 0; i < N; i++) {
      var t = i / (N - 1);
      tmp.copy(a).applyAxisAngle(axis, angle * t).multiplyScalar(RADIUS + 0.5 + lift * Math.sin(Math.PI * t));
      pts.push(tmp.clone());
      positions[3 * i] = tmp.x; positions[3 * i + 1] = tmp.y; positions[3 * i + 2] = tmp.z;
    }
    var geo = new THREE.BufferGeometry();
    geo.addAttribute('position', new THREE.BufferAttribute(positions, 3));
    geo.addAttribute('color', new THREE.BufferAttribute(colors, 3));
    geo.setDrawRange(0, 0);
    var line = new THREE.Line(geo, new THREE.LineBasicMaterial({
      vertexColors: THREE.VertexColors, blending: THREE.AdditiveBlending,
      transparent: true, depthWrite: false }));
    var head = makeGlow(color, 7);
    head.visible = false;
    overlay.add(line);
    overlay.add(head);

    var flight = 900 + 900 * angle / Math.PI, landed = false;
    var total = flight * (N - 1 + tail) / (N - 1);
    // looping "data packets" after the streamer settles; interval 0 = none (set via setRate)
    var pTail = 12, pPeak = 0.85, pTravel = flight * 0.9, pLen = N - 1 + pTail;
    var interval = 0, packets = [], heads = [head];
    var nextSpawn = start + total + Math.random() * 1000, idleReset = false, stopped = false;

    function setBase() {
      for (var i = 0; i < N; i++) {
        colors[3 * i] = color.r * base; colors[3 * i + 1] = color.g * base; colors[3 * i + 2] = color.b * base;
      }
      geo.attributes.color.needsUpdate = true;
    }

    tweens.push(function(now) {
      if (stopped) {
        overlay.remove(line); disposeObject(line);
        for (var q = 0; q < heads.length; q++) { overlay.remove(heads[q]); disposeObject(heads[q]); }
        return true;
      }
      if (now < start) return false;

      if (now - start < total) {
        var h = easeInOut(Math.min(1, (now - start) / flight)) * (N - 1);
        if (now - start > flight) h = (N - 1) + (now - start - flight) / (total - flight) * tail;
        var drawn = Math.min(N, Math.floor(h) + 1);
        geo.setDrawRange(0, drawn);
        for (var i = 0; i < drawn; i++) {
          var k = Math.max(base, 1 - (h - i) / tail);
          colors[3 * i] = color.r * k; colors[3 * i + 1] = color.g * k; colors[3 * i + 2] = color.b * k;
        }
        geo.attributes.color.needsUpdate = true;

        if (!landed) {
          head.visible = true;
          head.position.copy(pts[Math.min(N - 1, Math.round(h))]);
          if (h >= N - 1) {
            landed = true;
            head.visible = false;
            head.scale.set(4, 4, 1);
            flashAt(to.lat, to.lon, color);
            if (onLand) onLand();
          }
        }
        return false;
      }

      if (!landed) { landed = true; head.scale.set(4, 4, 1); if (onLand) onLand(); }
      geo.setDrawRange(0, N);
      if (interval > 0 && now >= nextSpawn) {
        packets.push(now);
        nextSpawn = now + interval * (0.75 + 0.5 * Math.random());
      }
      while (packets.length && (now - packets[0]) / pTravel > 1) packets.shift();
      if (!packets.length) {
        if (!idleReset) {
          setBase();
          for (var q = 0; q < heads.length; q++) heads[q].visible = false;
          idleReset = true;
        }
        return false;
      }
      idleReset = false;
      for (var j = 0; j < N; j++) {
        colors[3 * j] = color.r * base; colors[3 * j + 1] = color.g * base; colors[3 * j + 2] = color.b * base;
      }
      while (heads.length < packets.length) {
        var nh = makeGlow(color, 4);
        nh.visible = false;
        overlay.add(nh);
        heads.push(nh);
      }
      for (var p = 0; p < heads.length; p++) {
        if (p >= packets.length) { heads[p].visible = false; continue; }
        var ph = (now - packets[p]) / pTravel * pLen;
        for (var jj = Math.max(0, Math.ceil(ph - pTail)); jj <= Math.min(N - 1, Math.floor(ph)); jj++) {
          var kk = Math.max(base, pPeak * (1 - (ph - jj) / pTail));
          if (color.r * kk + color.g * kk + color.b * kk > colors[3 * jj] + colors[3 * jj + 1] + colors[3 * jj + 2]) {
            colors[3 * jj] = color.r * kk; colors[3 * jj + 1] = color.g * kk; colors[3 * jj + 2] = color.b * kk;
          }
        }
        heads[p].visible = ph <= N - 1;
        if (heads[p].visible) heads[p].position.copy(pts[Math.round(ph)]);
      }
      geo.attributes.color.needsUpdate = true;
      return false;
    });
    return {
      stop: function() { stopped = true; },
      setColor: function(c) {
        color.set(c);
        for (var q = 0; q < heads.length; q++) heads[q].material.color.set(c);
        idleReset = false;
      },
      // ms between packets; 0 stops new packets
      setRate: function(ms) {
        ms = ms > 0 ? ms : 0;
        if (ms > 0 && (interval === 0 || ms < interval))
          nextSpawn = Math.min(nextSpawn, Date.now() + ms * Math.random());
        interval = ms;
      }
    };
  }

  var barGeometry = new THREE.BoxGeometry(2.4, 2.4, 1);
  barGeometry.applyMatrix(new THREE.Matrix4().makeTranslation(0, 0, -0.5));

  function tweenBarHeight(bar, toH, ms, ease, onDone) {
    var fromH = bar.scale.z, t0 = Date.now();
    bar.userData.tweenId = (bar.userData.tweenId || 0) + 1;
    var myId = bar.userData.tweenId;
    tweens.push(function(now) {
      if (bar.userData.tweenId !== myId) return true;
      var p = Math.min(1, (now - t0) / ms);
      bar.scale.z = Math.max(0.1, fromH + (toH - fromH) * ease(p));
      if (p >= 1) { if (onDone) onDone(); return true; }
      return false;
    });
  }

  // Create (growing from 0) or retarget a bar.
  function setBar(id, lat, lng, height, color) {
    var bar = bars[id];
    if (!bar) {
      bar = new THREE.Mesh(barGeometry, new THREE.MeshBasicMaterial({ color: color }));
      bar.position.copy(latLngToVec3(lat, lng, RADIUS));
      bar.lookAt(new THREE.Vector3(0, 0, 0));
      bar.scale.z = 0.1;
      overlay.add(bar);
      bars[id] = bar;
      tweenBarHeight(bar, height, 1100, easeOutBack);
    } else {
      bar.material.color.set(color);
      tweenBarHeight(bar, height, 800, easeOutCubic);
    }
  }

  function removeBar(id) {
    var bar = bars[id];
    if (!bar) return;
    delete bars[id];
    tweenBarHeight(bar, 0.1, 600, easeOutCubic, function() {
      overlay.remove(bar);
      disposeObject(bar);
    });
  }

  function hasBar(id) { return !!bars[id]; }

  function clearOverlay() {
    tweens.length = 0;
    bars = {};
    for (var i = overlay.children.length - 1; i >= 0; i--) {
      var c = overlay.children[i];
      overlay.remove(c);
      disposeObject(c);
    }
  }

  function setPointsVisible(on) {
    if (this.points) this.points.visible = on;
  }

  function clearPoints() {
    if (this.points) {
      scene.remove(this.points);
      this.points.geometry.dispose();
      this.points.material.dispose();
      this.points = undefined;
    }
    this._baseGeometry = undefined;
  }

  this.addData = addData;
  this.createPoints = createPoints;
  this.clearPoints = clearPoints;
  this.setPointsVisible = setPointsVisible;
  this.focusOn = focusOn;
  this.setAutoRotate = setAutoRotate;
  this.pulseAt = pulseAt;
  this.launch = launch;
  this.setBar = setBar;
  this.removeBar = removeBar;
  this.hasBar = hasBar;
  this.clearOverlay = clearOverlay;
  this.colorFn = colorFn;
  this.renderer = renderer;
  this.scene = scene;

  return this;

};

