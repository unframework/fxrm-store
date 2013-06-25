<?php

namespace Fxrm\Store;

// @todo if an identity class is abstract, store the real class in a field + "Class" column
class Environment {
    private $backendMap;
    private $serializerMap;
    private $idClassMap;
    private $methodMap;

    private static $PRIMITIVE_TYPES = array('string', 'integer', 'int'); // @todo add more

    function __construct($configPath) {
        $config = json_decode(file_get_contents($configPath));

        // set up backends
        $this->backendMap = (object)array();

        foreach ($config->backends as $backendName => $backendArgs) {
            $backendClass = new \ReflectionClass(array_shift($backendArgs));
            $this->backendMap->$backendName = $backendClass->newInstanceArgs($backendArgs);
        }

        // set up serializers
        // @todo verify names
        $this->serializerMap = (object)array();
        $this->idClassMap = (object)array();

        foreach ($config->idClasses as $idClass => $backendName) {
            $this->idClassMap->$idClass = $backendName;
            $this->serializerMap->$idClass = new IdentitySerializer($idClass, $this->backendMap->$backendName);
        }

        foreach ($config->valueClasses as $valueClass) {
            $this->serializerMap->$valueClass = new ValueSerializer($valueClass);
        }

        $this->serializerMap->DateTime = new PassthroughSerializer();

        // copy over the method backend names
        // @todo verify names
        $this->methodMap = (object)array();

        foreach ($config->methods as $method => $backendName) {
            $this->methodMap->$method = $backendName;
        }
    }

    function implement($className) {
        $constructArguments = array_slice(func_get_args(), 1);

        $classInfo = new \ReflectionClass($className);

        $implementationName = 'FxrmStore_' . md5($className);
        $implementationSource = array();

        $implementationSource[] = 'class ' . $implementationName;
        $implementationSource[] = ' extends \\' . $classInfo->getName();
        $implementationSource[] = '{ private $s;';

        // implement constructor
        $constructorInfo = $classInfo->getConstructor();

        $implementationSource[] = 'function __construct($s, $args) {';
        $implementationSource[] = '$this->s = $s;'; // this must be set before calling parent

        if ($constructorInfo) {
            $implementationSource[] = 'parent::__construct(';

            $count = count($constructorInfo->getParameters());

            if (count($constructArguments) !== $count) {
                throw new \Exception('expecting ' . $count . ' constructor argument(s)');
            }

            if ($count > 0) {
                foreach (range(0, $count - 1) as $i) {
                    $implementationSource[] = ($i === 0 ? '' : ',') . '$args[' . $i . ']';
                }
            }

            $implementationSource[] = ');';
        }

        $implementationSource[] = '}';

        // implement all abstract methods
        foreach ($classInfo->getMethods(\ReflectionMethod::IS_ABSTRACT) as $methodInfo) {
            $name = $methodInfo->getName();

            if (substr($name, 0, 3) === 'get') {
                $implementationSource[] = $this->defineGetter($methodInfo);
            } elseif (substr($name, 0, 4) === 'find') {
                $implementationSource[] = $this->defineFinder($methodInfo);
            } else {
                $implementationSource[] = $this->defineSetter($methodInfo);
            }
        }

        $implementationSource[] = 'public static function _getStorable($instance) { return $instance->s; }';
        $implementationSource[] = '}';

        //echo(join('', $implementationSource));
        eval(join('', $implementationSource));

        return new $implementationName($this, $constructArguments);
    }

    public function extern($obj) {
        $className = get_class($obj);
        $serializer = $this->serializerMap->$className;

        // explicitly deal with identities only - values are not a concern
        if (!($serializer instanceof IdentitySerializer)) {
            throw new \Exception('only identities can be externalized'); // developer error
        }

        return $serializer->externWithoutCreating($obj);
    }

    public function intern($className, $id) {
        // explicitly deal with identities only - values are not a concern
        if ( ! $this->isIdentityClass($className)) {
            throw new \Exception('only identities can be externalized'); // developer error
        }

        // explicitly deal with identities only - values are not a concern
        return $this->serializerMap->$className->intern($id);
    }

    private function isIdentityClass($class) {
        return property_exists($this->serializerMap, $class) && $this->serializerMap->$class instanceof IdentitySerializer;
    }

    private function internRow($className, $fieldClassMap, $value) {
        $result = new $className();

        // copying strictly only the defined properties
        foreach ($fieldClassMap as $k => $class) {
            $result->$k = $this->internAny($class, $value->$k);
        }

        return $result;
    }

    private function internAny($class, $value) {
        return $class === null ? $value : $this->serializerMap->$class->intern($value);
    }

    private function externAny($class, $value) {
        return $class === null ? $value : $this->serializerMap->$class->extern($value);
    }

    function get($backendName, $implName, $idClass, $idObj, $propertyClass, $propertyName) {
        $id = $this->externAny($idClass, $idObj);

        $value = $this->backendMap->$backendName->get($implName, $idClass, $id, $this->getBackendType($propertyClass), $propertyName);

        return $this->internAny($propertyClass, $value);
    }

    function set($backendName, $implName, $idClass, $idObj, $properties) {
        $id = $this->externAny($idClass, $idObj);

        $values = array();

        foreach ($properties as $propertyName => $qualifiedValue) {
            list($propertyClass, $value) = $qualifiedValue;

            $values[$propertyName] = $this->externAny($propertyClass, $value);
        }

        $this->backendMap->$backendName->set($implName, $idClass, $id, $values);
    }

    function find($backendName, $implName, $returnClass, $fieldClassMap, $properties, $returnArray) {
        $values = array();

        foreach ($properties as $propertyName => $qualifiedValue) {
            list($propertyClass, $value) = $qualifiedValue;

            $values[$propertyName] = $this->externAny($propertyClass, $value);
        }

        $idClass = $this->isIdentityClass($returnClass) ? $returnClass : null;

        $data = $this->backendMap->$backendName->find($implName, $idClass, $values, $fieldClassMap ? $this->getBackendTypeMap($fieldClassMap) : $this->getBackendType($returnClass), $returnArray);

        if ($returnArray) {
            if ($fieldClassMap) {
                foreach ($data as &$value) {
                    $value = $this->internRow($returnClass, $fieldClassMap, $value);
                }
            } else {
                foreach ($data as &$value) {
                    $value = $this->internAny($returnClass, $value);
                }
            }
        } else {
            if ($fieldClassMap) {
                $data = $this->internRow($returnClass, $fieldClassMap, $data);
            } else {
                $data = $this->internAny($returnClass, $data);
            }
        }

        return $data;
    }

    private function defineGetter(\ReflectionMethod $info) {
        $signature = $this->getSignature($info);

        if (count((array)$signature->parameters) != 1) {
            throw new \Exception('getters must have one parameter');
        }

        if ($signature->returnArray || $signature->returnFieldMap) {
            throw new \Exception('getters cannot return arrays or row objects');
        }

        if ( ! preg_match('/([^\\\\]+)Id$/', $signature->firstParameterClass, $idMatch)) {
            throw new \Exception('target class must be an identity');
        }

        $fullPrefix = 'get' . ucfirst($idMatch[1]);

        if (strpos($info->getName(), $fullPrefix) !== 0) {
            throw new \Exception('getter must include target class name: ' . $fullPrefix);
        }

        $propertyName = lcfirst(substr($info->getName(), strlen($fullPrefix)));
        $backendName = $this->getBackendName($signature->fullName, $signature->firstParameterClass, $propertyName);

        $source[] = $signature->preamble . ' {';
        $source[] = 'return $this->s->get(';
        $source[] = var_export($backendName, true) . ', ';
        $source[] = var_export($signature->fullName, true) . ', ';
        $source[] = var_export($signature->firstParameterClass, true) . ', ';
        $source[] = '$a0, ';
        $source[] = var_export($signature->returnType, true) . ', ';
        $source[] = var_export($propertyName, true);
        $source[] = ');';
        $source[] = '}';

        return join('', $source);
    }

    private function defineSetter(\ReflectionMethod $info) {
        $signature = $this->getSignature($info);

        if (count((array)$signature->parameters) < 2) {
            throw new \Exception('setters must have an id parameter and at least one value parameter');
        }

        $backendName = $this->getBackendName($signature->fullName, $signature->firstParameterClass, $signature->firstParameterName);

        $source[] = $signature->preamble . ' {';
        $source[] = '$this->s->set(';
        $source[] = var_export($backendName, true) . ', ';
        $source[] = var_export($signature->fullName, true) . ', ';
        $source[] = var_export($signature->firstParameterClass, true) . ', ';
        $source[] = '$a0, ';
        $source[] = 'array(';

        $count = 0;
        foreach ($signature->parameters as $param => $class) {
            // skip the id parameter
            if ($count > 0) {
                $source[] = ($count === 1 ? '' : ',');
                $source[] = var_export($param, true);
                $source[] = ' => array(';
                $source[] = var_export($class, true) . ', ' . '$a' . $count;
                $source[] = ')';
            }

            $count += 1;
        }

        $source[] = '));';
        $source[] = '}';

        return join('', $source);
    }

    private function defineFinder(\ReflectionMethod $info) {
        $signature = $this->getSignature($info);

        $backendName = $this->getBackendName($signature->fullName, $signature->returnType, null);

        $source[] = $signature->preamble . ' {';
        $source[] = 'return $this->s->find(';
        $source[] = var_export($backendName, true) . ', ';
        $source[] = var_export($signature->fullName, true) . ', ';
        $source[] = var_export($signature->returnType, true) . ', ';
        $source[] = var_export($signature->returnFieldMap, true) . ', ';
        $source[] = 'array(';

        $count = 0;
        foreach ($signature->parameters as $param => $class) {
            $source[] = ($count === 0 ? '' : ',');
            $source[] = var_export($param, true);
            $source[] = ' => array(';
            $source[] = var_export($class, true) . ', ' . '$a' . $count;
            $source[] = ')';

            $count += 1;
        }

        $source[] = '),';
        $source[] = $signature->returnArray ? 'true' : 'false';
        $source[] = ');';
        $source[] = '}';

        return join('', $source);
    }

    private function getBackendName($implName, $idClass, $firstPropertyName) {
        // see if explicit backend designation is set
        if (property_exists($this->methodMap, $implName)) {
            return $this->methodMap->$implName;
        }

        // otherwise, use the identity class
        // @todo also consider property name
        if (property_exists($this->idClassMap, $idClass)) {
            return $this->idClassMap->$idClass;
        }

        throw new \Exception('cannot find backend for ' . $implName . ' using ' . $idClass);
    }

    private function getBackendType($class) {
        return $class === 'DateTime' ? 'DATE_TIME' : null;
    }

    private function getBackendTypeMap($classMap) {
        return array_map(array($this, 'getBackendType'), $classMap);
    }

    private function getRealClass(\ReflectionClass $declaringClass, $classHint) {
        // convert to full class name
        if (array_search($classHint, self::$PRIMITIVE_TYPES) !== FALSE) {
            return null;
        } elseif ($classHint === 'object') {
            // special object shorthand
            return '\\stdClass';
        } else {
            return $classHint[0] === '\\' ?
                substr($classHint, 1) :
                $declaringClass->getNamespaceName() . '\\' . $classHint;
        }
    }

    private function getPropertyClass(\ReflectionProperty $prop) {
        if (preg_match('/@var\\s+(\\S+)/', $prop->getDocComment(), $commentMatch)) {
            $targetIdClassHint = $commentMatch[1];

            return $this->getRealClass($prop->getDeclaringClass(), $targetIdClassHint);
        }

        return null;
    }

    private function getSignature(\ReflectionMethod $info) {
        $signature = (object)array();

        $signature->name = $info->getName();

        $signature->fullName = $info->getDeclaringClass()->getName() . '\\' . $signature->name;

        $comment = $info->getDocComment();
        if (preg_match('/@return\\s+(\\S+)/', $comment, $commentMatch)) {
            // @todo ignore standard names like "string" and others
            $targetIdClassHint = $commentMatch[1];

            $isArray = substr($targetIdClassHint, -2) === '[]';

            if ($isArray) {
                $targetIdClassHint = substr($targetIdClassHint, 0, -2);
            }

            $targetIdClass = $this->getRealClass($info->getDeclaringClass(), $targetIdClassHint);

            $fieldMap = null;

            if ($targetIdClass !== null) {
                $targetClassInfo = new \ReflectionClass($targetIdClass);

                if ( ! isset($this->serializerMap->$targetIdClass)) {
                    $fieldMap = array();

                    foreach ($targetClassInfo->getProperties() as $prop) {
                        if ($prop->isStatic()) {
                            continue;
                        }

                        if ( ! $prop->isPublic()) {
                            throw new \Exception('row object must only contain public properties');
                        }

                        $fieldMap[$prop->getName()] = $this->getPropertyClass($prop);
                    }
                }
            }

            $signature->returnArray = $isArray;
            $signature->returnType = $targetIdClass;
            $signature->returnFieldMap = $fieldMap;
        } else {
            $signature->returnArray = false;
            $signature->returnType = null;
            $signature->returnFieldMap = null;
        }

        $signature->parameters = (object)array();
        foreach ($info->getParameters() as $param) {
            $class = $param->getClass();
            $signature->parameters->{$param->getName()} = ($class ? $class->getName() : null);
        }

        $signature->preamble = 'function ' . $signature->name . '(';

        $count = 0;
        foreach ($signature->parameters as $param => $class) {
            if ($count === 0) {
                $signature->firstParameterName = $param;
                $signature->firstParameterClass = $class;
            }

            $signature->preamble .= ($count > 0 ? ',' : '') . ($class ? "\\$class" : '') . ' $a' . $count;
            $count += 1;
        }

        $signature->preamble .= ')';

        return $signature;
    }
}

?>
